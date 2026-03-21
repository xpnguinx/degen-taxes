<?php

declare(strict_types=1);

const APP_NAME = 'degen-taxes';
const DB_FILE = __DIR__ . '/sol_tax_ledger.sqlite';
const APP_VERSION = '2.0.0';

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Los_Angeles');

// Load .env file
function loadEnv(): void
{
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}
loadEnv();

function db(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) return $db;

    $db = new SQLite3(DB_FILE);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS wallets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, address TEXT NOT NULL UNIQUE,
        notes TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        last_synced_at TEXT, last_sync_status TEXT, last_sync_message TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, wallet_id INTEGER NOT NULL, signature TEXT NOT NULL,
        slot INTEGER, block_time INTEGER, tx_type TEXT, source TEXT, description TEXT,
        fee_lamports INTEGER NOT NULL DEFAULT 0, native_in_sol REAL NOT NULL DEFAULT 0,
        native_out_sol REAL NOT NULL DEFAULT 0, token_in_json TEXT NOT NULL DEFAULT \'[]\',
        token_out_json TEXT NOT NULL DEFAULT \'[]\', raw_json TEXT NOT NULL,
        inferred_category TEXT NOT NULL DEFAULT \'unreviewed\', manual_category TEXT,
        tax_treatment TEXT NOT NULL DEFAULT \'review\', usd_value_manual REAL, basis_usd_manual REAL,
        notes TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        FOREIGN KEY(wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
        UNIQUE(wallet_id, signature)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tx_transfers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER NOT NULL,
        transfer_kind TEXT NOT NULL, direction TEXT NOT NULL, mint TEXT, symbol TEXT,
        amount REAL NOT NULL DEFAULT 0, from_account TEXT, to_account TEXT,
        FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_transactions_wallet_time ON transactions(wallet_id, block_time DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_transactions_wallet_category ON transactions(wallet_id, inferred_category)');

    // Auto-seed from .env on first run
    if (isset($_ENV['HELIUS_API_KEY']) && $_ENV['HELIUS_API_KEY'] !== 'your_helius_api_key_here') {
        $existing = getSetting('helius_api_key', '');
        if ($existing === '') {
            setSetting('helius_api_key', $_ENV['HELIUS_API_KEY']);
            setSetting('helius_api_base', $_ENV['HELIUS_API_BASE'] ?? 'https://api-mainnet.helius-rpc.com');
        }
    }
    return $db;
}

function nowIso(): string { return date('c'); }
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function getSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row['value'] ?? $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :u)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
    $stmt->execute();
}

function validateSolanaAddress(string $address): bool
{
    return (bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
}

function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flashes'])) $_SESSION['flashes'] = [];
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function allWallets(): array
{
    $result = db()->query('SELECT * FROM wallets ORDER BY updated_at DESC, id DESC');
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getWallet(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM wallets WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function walletStats(int $walletId): array
{
    $stmt = db()->prepare('SELECT COUNT(*) AS tx_count, COALESCE(SUM(native_in_sol),0) AS sol_in,
        COALESCE(SUM(native_out_sol),0) AS sol_out,
        COALESCE(SUM(CASE WHEN manual_category IS NOT NULL AND manual_category != "" THEN 1 ELSE 0 END),0) AS reviewed,
        COALESCE(SUM(CASE WHEN usd_value_manual IS NOT NULL THEN usd_value_manual ELSE 0 END),0) AS manual_usd_total
        FROM transactions WHERE wallet_id = :wid');
    $stmt->bindValue(':wid', $walletId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: [];
    return [
        'tx_count' => (int)($row['tx_count'] ?? 0), 'sol_in' => (float)($row['sol_in'] ?? 0),
        'sol_out' => (float)($row['sol_out'] ?? 0), 'reviewed' => (int)($row['reviewed'] ?? 0),
        'manual_usd_total' => (float)($row['manual_usd_total'] ?? 0),
    ];
}

function getTransactionsForWallet(int $walletId, array $filters = []): array
{
    $where = ['wallet_id = :wallet_id'];
    $params = [':wallet_id' => [$walletId, SQLITE3_INTEGER]];
    if (!empty($filters['q'])) {
        $where[] = '(signature LIKE :q OR description LIKE :q OR tx_type LIKE :q OR source LIKE :q OR notes LIKE :q)';
        $params[':q'] = ['%' . $filters['q'] . '%', SQLITE3_TEXT];
    }
    if (!empty($filters['category'])) {
        if ($filters['category'] === 'manual') {
            $where[] = 'manual_category IS NOT NULL AND manual_category != ""';
        } elseif ($filters['category'] === 'unreviewed') {
            $where[] = '(manual_category IS NULL OR manual_category = "")';
        } else {
            $where[] = '(COALESCE(NULLIF(manual_category, ""), inferred_category) = :category)';
            $params[':category'] = [$filters['category'], SQLITE3_TEXT];
        }
    }
    $sql = 'SELECT * FROM transactions WHERE ' . implode(' AND ', $where) . ' ORDER BY block_time DESC, id DESC LIMIT 5000';
    $stmt = db()->prepare($sql);
    foreach ($params as $name => [$value, $type]) $stmt->bindValue($name, $value, $type);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getTransactionById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM transactions WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function getTransferRows(int $transactionId): array
{
    $stmt = db()->prepare('SELECT * FROM tx_transfers WHERE transaction_id = :id ORDER BY id ASC');
    $stmt->bindValue(':id', $transactionId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function apiBaseUrl(): string { return rtrim(getSetting('helius_api_base', 'https://api-mainnet.helius-rpc.com'), '/'); }
function heliusApiKey(): string { return trim(getSetting('helius_api_key', '')); }

function heliusGet(string $path, array $query = []): array
{
    $apiKey = heliusApiKey();
    if ($apiKey === '') throw new RuntimeException('missing helius api key — add it in settings or .env first 🔑');
    $query['api-key'] = $apiKey;
    $url = apiBaseUrl() . $path . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('curl error: ' . curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('invalid json response from helius');
    if ($status >= 400) {
        $message = $data['error']['message'] ?? $data['message'] ?? ('http ' . $status);
        throw new RuntimeException('helius api error: ' . $message);
    }
    return $data;
}

function classifyTransaction(array $tx, string $walletAddress): array
{
    $wallet = strtolower($walletAddress);
    $nativeIn = 0.0; $nativeOut = 0.0; $tokenIn = []; $tokenOut = [];
    $feeLamports = (int)($tx['fee'] ?? 0);

    foreach (($tx['nativeTransfers'] ?? []) as $transfer) {
        $from = strtolower((string)($transfer['fromUserAccount'] ?? $transfer['from'] ?? ''));
        $to = strtolower((string)($transfer['toUserAccount'] ?? $transfer['to'] ?? ''));
        $sol = ((float)($transfer['amount'] ?? 0)) / 1e9;
        if ($to === $wallet) $nativeIn += $sol;
        if ($from === $wallet) $nativeOut += $sol;
    }
    foreach (($tx['tokenTransfers'] ?? []) as $transfer) {
        $from = strtolower((string)($transfer['fromUserAccount'] ?? $transfer['fromTokenAccount'] ?? ''));
        $to = strtolower((string)($transfer['toUserAccount'] ?? $transfer['toTokenAccount'] ?? ''));
        $amount = (float)($transfer['tokenAmount'] ?? 0);
        $mint = (string)($transfer['mint'] ?? '');
        $symbol = (string)($transfer['symbol'] ?? '');
        $entry = ['mint'=>$mint,'symbol'=>$symbol,'amount'=>$amount,
            'from'=>(string)($transfer['fromTokenAccount'] ?? $transfer['fromUserAccount'] ?? ''),
            'to'=>(string)($transfer['toTokenAccount'] ?? $transfer['toUserAccount'] ?? '')];
        if ($to === $wallet) $tokenIn[] = $entry;
        if ($from === $wallet) $tokenOut[] = $entry;
    }
    $type = strtoupper((string)($tx['type'] ?? 'UNKNOWN'));
    $description = (string)($tx['description'] ?? '');
    $inferred = 'unreviewed';
    if ($nativeIn > 0 && $nativeOut == 0 && count($tokenOut) === 0) $inferred = 'inflow';
    elseif ($nativeOut > 0 && $nativeIn == 0 && count($tokenIn) === 0) $inferred = 'outflow';
    elseif (($nativeIn > 0 && $nativeOut > 0) || (!empty($tokenIn) && !empty($tokenOut))) $inferred = 'swap_or_complex';
    if (!in_array($type, ['SWAP','NFT_SALE','TOKEN_MINT','BURN','TRANSFER','UNKNOWN'], true) && $inferred === 'unreviewed') $inferred = 'program_event';
    if (stripos($description, 'transfer') !== false && $inferred === 'unreviewed') $inferred = 'transfer';
    return [
        'fee_lamports' => $feeLamports, 'native_in_sol' => round($nativeIn, 9),
        'native_out_sol' => round($nativeOut, 9),
        'token_in_json' => json_encode($tokenIn, JSON_UNESCAPED_SLASHES),
        'token_out_json' => json_encode($tokenOut, JSON_UNESCAPED_SLASHES),
        'inferred_category' => $inferred, 'tx_type' => $type,
        'source' => (string)($tx['source'] ?? ''), 'description' => $description,
        'transfers' => ['token_in' => $tokenIn, 'token_out' => $tokenOut],
    ];
}

function saveEnhancedTransaction(int $walletId, string $walletAddress, array $tx): void
{
    $db = db();
    $signature = (string)($tx['signature'] ?? '');
    if ($signature === '') return;
    $parsed = classifyTransaction($tx, $walletAddress);
    $now = nowIso();
    $stmt = $db->prepare('INSERT INTO transactions (
        wallet_id, signature, slot, block_time, tx_type, source, description,
        fee_lamports, native_in_sol, native_out_sol, token_in_json, token_out_json,
        raw_json, inferred_category, created_at, updated_at
    ) VALUES (:wallet_id,:sig,:slot,:bt,:tx_type,:source,:desc,:fee,:nin,:nout,:tin,:tout,:raw,:ic,:ca,:ua)
    ON CONFLICT(wallet_id, signature) DO UPDATE SET
        slot=excluded.slot, block_time=excluded.block_time, tx_type=excluded.tx_type,
        source=excluded.source, description=excluded.description, fee_lamports=excluded.fee_lamports,
        native_in_sol=excluded.native_in_sol, native_out_sol=excluded.native_out_sol,
        token_in_json=excluded.token_in_json, token_out_json=excluded.token_out_json,
        raw_json=excluded.raw_json, inferred_category=excluded.inferred_category, updated_at=excluded.updated_at');
    $stmt->bindValue(':wallet_id', $walletId, SQLITE3_INTEGER);
    $stmt->bindValue(':sig', $signature, SQLITE3_TEXT);
    $stmt->bindValue(':slot', (int)($tx['slot'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':bt', (int)($tx['timestamp'] ?? $tx['blockTime'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':tx_type', $parsed['tx_type'], SQLITE3_TEXT);
    $stmt->bindValue(':source', $parsed['source'], SQLITE3_TEXT);
    $stmt->bindValue(':desc', $parsed['description'], SQLITE3_TEXT);
    $stmt->bindValue(':fee', $parsed['fee_lamports'], SQLITE3_INTEGER);
    $stmt->bindValue(':nin', $parsed['native_in_sol'], SQLITE3_FLOAT);
    $stmt->bindValue(':nout', $parsed['native_out_sol'], SQLITE3_FLOAT);
    $stmt->bindValue(':tin', $parsed['token_in_json'], SQLITE3_TEXT);
    $stmt->bindValue(':tout', $parsed['token_out_json'], SQLITE3_TEXT);
    $stmt->bindValue(':raw', json_encode($tx, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $stmt->bindValue(':ic', $parsed['inferred_category'], SQLITE3_TEXT);
    $stmt->bindValue(':ca', $now, SQLITE3_TEXT);
    $stmt->bindValue(':ua', $now, SQLITE3_TEXT);
    $stmt->execute();
    $txId = (int)$db->lastInsertRowID();
    if ($txId === 0) {
        $lookup = $db->prepare('SELECT id FROM transactions WHERE wallet_id=:wid AND signature=:sig');
        $lookup->bindValue(':wid', $walletId, SQLITE3_INTEGER);
        $lookup->bindValue(':sig', $signature, SQLITE3_TEXT);
        $row = $lookup->execute()->fetchArray(SQLITE3_ASSOC);
        $txId = (int)($row['id'] ?? 0);
    }
    if ($txId > 0) {
        $del = $db->prepare('DELETE FROM tx_transfers WHERE transaction_id = :id');
        $del->bindValue(':id', $txId, SQLITE3_INTEGER);
        $del->execute();
        foreach ($parsed['transfers']['token_in'] as $t) insertTransfer($txId, 'token', 'in', $t);
        foreach ($parsed['transfers']['token_out'] as $t) insertTransfer($txId, 'token', 'out', $t);
        foreach (($tx['nativeTransfers'] ?? []) as $transfer) {
            $from = strtolower((string)($transfer['fromUserAccount'] ?? $transfer['from'] ?? ''));
            $to = strtolower((string)($transfer['toUserAccount'] ?? $transfer['to'] ?? ''));
            $entry = ['mint'=>'SOL','symbol'=>'SOL','amount'=>((float)($transfer['amount']??0))/1e9,
                'from'=>(string)($transfer['fromUserAccount']??$transfer['from']??''),
                'to'=>(string)($transfer['toUserAccount']??$transfer['to']??'')];
            if ($from === strtolower($walletAddress)) insertTransfer($txId, 'native', 'out', $entry);
            if ($to === strtolower($walletAddress)) insertTransfer($txId, 'native', 'in', $entry);
        }
    }
}

function insertTransfer(int $transactionId, string $kind, string $direction, array $transfer): void
{
    $stmt = db()->prepare('INSERT INTO tx_transfers (transaction_id,transfer_kind,direction,mint,symbol,amount,from_account,to_account)
        VALUES (:tid,:tk,:dir,:mint,:sym,:amt,:from,:to)');
    $stmt->bindValue(':tid', $transactionId, SQLITE3_INTEGER);
    $stmt->bindValue(':tk', $kind, SQLITE3_TEXT);
    $stmt->bindValue(':dir', $direction, SQLITE3_TEXT);
    $stmt->bindValue(':mint', (string)($transfer['mint'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':sym', (string)($transfer['symbol'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':amt', (float)($transfer['amount'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':from', (string)($transfer['from'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':to', (string)($transfer['to'] ?? ''), SQLITE3_TEXT);
    $stmt->execute();
}

function syncWallet(int $walletId, int $pages = 10, int $limit = 100): array
{
    $wallet = getWallet($walletId);
    if (!$wallet) throw new RuntimeException('wallet not found');
    $address = $wallet['address'];
    $before = null; $saved = 0; $pageCount = 0;
    for ($i = 0; $i < $pages; $i++) {
        $query = ['limit' => $limit];
        if ($before) $query['before'] = $before;
        $data = heliusGet('/v0/addresses/' . rawurlencode($address) . '/transactions', $query);
        if (!is_array($data) || empty($data)) break;
        $pageCount++;
        foreach ($data as $tx) { if (is_array($tx)) { saveEnhancedTransaction($walletId, $address, $tx); $saved++; } }
        $last = end($data);
        $before = is_array($last) ? (string)($last['signature'] ?? '') : '';
        if ($before === '' || count($data) < $limit) break;
    }
    $stmt = db()->prepare('UPDATE wallets SET updated_at=:u, last_synced_at=:ls, last_sync_status=:lss, last_sync_message=:lsm WHERE id=:id');
    $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
    $stmt->bindValue(':ls', nowIso(), SQLITE3_TEXT);
    $stmt->bindValue(':lss', 'success', SQLITE3_TEXT);
    $stmt->bindValue(':lsm', 'synced ' . $saved . ' tx rows across ' . $pageCount . ' page(s)', SQLITE3_TEXT);
    $stmt->bindValue(':id', $walletId, SQLITE3_INTEGER);
    $stmt->execute();
    return ['saved' => $saved, 'pages' => $pageCount];
}

function updateWalletSyncFailure(int $walletId, string $message): void
{
    $stmt = db()->prepare('UPDATE wallets SET updated_at=:u, last_synced_at=:ls, last_sync_status=:lss, last_sync_message=:lsm WHERE id=:id');
    $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
    $stmt->bindValue(':ls', nowIso(), SQLITE3_TEXT);
    $stmt->bindValue(':lss', 'error', SQLITE3_TEXT);
    $stmt->bindValue(':lsm', $message, SQLITE3_TEXT);
    $stmt->bindValue(':id', $walletId, SQLITE3_INTEGER);
    $stmt->execute();
}

function formatBlockTime(int $timestamp, bool $withSeconds = false): string
{
    if ($timestamp <= 0) return '-';
    return date($withSeconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i', $timestamp);
}

function shortSig(string $sig, int $front = 10, int $back = 8): string
{
    if (strlen($sig) <= ($front + $back + 3)) return $sig;
    return substr($sig, 0, $front) . '...' . substr($sig, -$back);
}

function effectiveCategory(array $tx): string
{
    $manual = trim((string)($tx['manual_category'] ?? ''));
    return $manual !== '' ? $manual : (string)($tx['inferred_category'] ?? 'unreviewed');
}

function tokenSummary(array $tx): string
{
    $in = json_decode((string)$tx['token_in_json'], true) ?: [];
    $out = json_decode((string)$tx['token_out_json'], true) ?: [];
    $parts = [];
    foreach ($in as $row) $parts[] = '+' . rtrim(rtrim(number_format((float)($row['amount']??0),9,'.',''),'0'),'.') . ' ' . ((string)($row['symbol']??'') ?: 'token');
    foreach ($out as $row) $parts[] = '-' . rtrim(rtrim(number_format((float)($row['amount']??0),9,'.',''),'0'),'.') . ' ' . ((string)($row['symbol']??'') ?: 'token');
    return implode(' | ', $parts);
}

function categoryOptions(): array
{
    return ['unreviewed'=>'unreviewed','income_receipt'=>'income receipt 📥','self_transfer'=>'self transfer 🔄',
        'sale_for_usd'=>'sale for usd 💵','swap'=>'swap 🔀','gift'=>'gift 🎁','debt_payment'=>'debt payment 💳',
        'reinvestment'=>'reinvestment ♻️','expense'=>'expense 🧾','owner_draw'=>'owner draw 🏦','other'=>'other'];
}

function taxTreatmentOptions(): array
{
    return ['review'=>'⏳ review','income'=>'📥 income','non_taxable_transfer'=>'🔄 non-taxable transfer',
        'disposal'=>'📤 disposal','gift'=>'🎁 gift','expense'=>'🧾 expense','owner_draw'=>'🏦 owner draw','ignore'=>'🚫 ignore'];
}

// --- Flow Analysis Functions ---

function getFlowAnalysis(array $wallets): array
{
    $addressMap = [];
    foreach ($wallets as $w) $addressMap[strtolower($w['address'])] = $w['label'];
    $allAddresses = array_keys($addressMap);

    $selfTransfers = 0.0; $incomeTotal = 0.0; $disposalTotal = 0.0; $swapCount = 0;
    $flows = []; $finalBalances = [];

    foreach ($wallets as $w) {
        $wid = (int)$w['id'];
        $addr = strtolower($w['address']);
        $netSol = 0.0;
        $result = db()->prepare('SELECT * FROM tx_transfers WHERE transaction_id IN (SELECT id FROM transactions WHERE wallet_id=:wid)');
        $result->bindValue(':wid', $wid, SQLITE3_INTEGER);
        $rows = $result->execute();
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $from = strtolower((string)($row['from_account'] ?? ''));
            $to = strtolower((string)($row['to_account'] ?? ''));
            $amt = (float)($row['amount'] ?? 0);
            $sym = (string)($row['symbol'] ?? $row['mint'] ?? 'SOL');
            if ($row['direction'] === 'in') $netSol += $amt;
            else $netSol -= $amt;

            if ($row['direction'] === 'out' && in_array($to, $allAddresses, true)) {
                $selfTransfers += ($sym === 'SOL') ? $amt : 0;
                $flowKey = $addr . '>' . $to;
                if (!isset($flows[$flowKey])) $flows[$flowKey] = ['from'=>$addr,'to'=>$to,'sol'=>0,'tokens'=>[],'type'=>'self_transfer'];
                $flows[$flowKey]['sol'] += ($sym === 'SOL') ? $amt : 0;
            } elseif ($row['direction'] === 'in' && !in_array($from, $allAddresses, true)) {
                $incomeTotal += ($sym === 'SOL') ? $amt : 0;
                $flowKey = $from . '>' . $addr;
                if (!isset($flows[$flowKey])) $flows[$flowKey] = ['from'=>$from,'to'=>$addr,'sol'=>0,'tokens'=>[],'type'=>'income'];
                $flows[$flowKey]['sol'] += ($sym === 'SOL') ? $amt : 0;
            } elseif ($row['direction'] === 'out' && !in_array($to, $allAddresses, true)) {
                $disposalTotal += ($sym === 'SOL') ? $amt : 0;
                $flowKey = $addr . '>' . $to;
                if (!isset($flows[$flowKey])) $flows[$flowKey] = ['from'=>$addr,'to'=>$to,'sol'=>0,'tokens'=>[],'type'=>'disposal'];
                $flows[$flowKey]['sol'] += ($sym === 'SOL') ? $amt : 0;
            }
        }
        $finalBalances[$w['label']] = $netSol;
    }
    $txResult = db()->query('SELECT COUNT(*) AS c FROM transactions WHERE inferred_category = "swap_or_complex"');
    $swapRow = $txResult->fetchArray(SQLITE3_ASSOC);
    $swapCount = (int)($swapRow['c'] ?? 0);
    return [
        'self_transfers_sol' => round($selfTransfers, 4), 'income_sol' => round($incomeTotal, 4),
        'disposal_sol' => round($disposalTotal, 4), 'swap_count' => $swapCount,
        'flows' => array_values($flows), 'final_balances' => $finalBalances,
    ];
}

function getFlowSankeyData(array $wallets, array $flowAnalysis): array
{
    $addressMap = [];
    foreach ($wallets as $w) $addressMap[strtolower($w['address'])] = $w['label'];
    $nodeSet = []; $nodes = []; $links = [];
    foreach ($flowAnalysis['flows'] as $flow) {
        if ($flow['sol'] < 0.001) continue;
        $fromLabel = $addressMap[$flow['from']] ?? substr($flow['from'], 0, 8) . '...';
        $toLabel = $addressMap[$flow['to']] ?? substr($flow['to'], 0, 8) . '...';
        if (!isset($nodeSet[$fromLabel])) { $nodeSet[$fromLabel] = count($nodes); $nodes[] = ['name'=>$fromLabel]; }
        if (!isset($nodeSet[$toLabel])) { $nodeSet[$toLabel] = count($nodes); $nodes[] = ['name'=>$toLabel]; }
        $links[] = ['source'=>$nodeSet[$fromLabel],'target'=>$nodeSet[$toLabel],'value'=>round($flow['sol'],4),'type'=>$flow['type']];
    }
    return ['nodes'=>$nodes, 'links'=>$links];
}

function exportCsv(array $transactions, array $wallet): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="degen_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $wallet['label']) . '_ledger.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['wallet_label','wallet_address','date_local','signature','tx_type','source','description',
        'native_in_sol','native_out_sol','fee_sol','effective_category','tax_treatment',
        'usd_value_manual','basis_usd_manual','gain_loss_estimate_manual','notes'], ',', '"', '');
    foreach ($transactions as $tx) {
        $usd = $tx['usd_value_manual'] !== null ? (float)$tx['usd_value_manual'] : null;
        $basis = $tx['basis_usd_manual'] !== null ? (float)$tx['basis_usd_manual'] : null;
        $gainLoss = ($usd !== null && $basis !== null) ? ($usd - $basis) : null;
        fputcsv($out, [$wallet['label'],$wallet['address'],formatBlockTime((int)$tx['block_time'],true),
            $tx['signature'],$tx['tx_type'],$tx['source'],$tx['description'],(float)$tx['native_in_sol'],
            (float)$tx['native_out_sol'],((int)$tx['fee_lamports'])/1e9,effectiveCategory($tx),
            $tx['tax_treatment'],$usd,$basis,$gainLoss,$tx['notes']], ',', '"', '');
    }
    fclose($out);
    exit;
}

function exportTaxSummary(array $wallets, array $flowAnalysis): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="degen_tax_summary.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['tax_bucket','from_wallet','to_wallet','amount_sol','flow_type'], ',', '"', '');
    foreach ($flowAnalysis['flows'] as $flow) {
        $addressMap = [];
        foreach ($wallets as $w) $addressMap[strtolower($w['address'])] = $w['label'];
        $fromLabel = $addressMap[$flow['from']] ?? $flow['from'];
        $toLabel = $addressMap[$flow['to']] ?? $flow['to'];
        fputcsv($out, [$flow['type'], $fromLabel, $toLabel, round($flow['sol'],6), $flow['type']], ',', '"', '');
    }
    fputcsv($out, [], ',', '"', '');
    fputcsv($out, ['summary'], ',', '"', '');
    fputcsv($out, ['self_transfers_sol', $flowAnalysis['self_transfers_sol']], ',', '"', '');
    fputcsv($out, ['income_sol', $flowAnalysis['income_sol']], ',', '"', '');
    fputcsv($out, ['disposal_sol', $flowAnalysis['disposal_sol']], ',', '"', '');
    fputcsv($out, ['swap_count', $flowAnalysis['swap_count']], ',', '"', '');
    fclose($out);
    exit;
}

// --- Routing & POST handling ---

session_start();
db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_settings') {
            setSetting('helius_api_key', trim((string)($_POST['helius_api_key'] ?? '')));
            setSetting('helius_api_base', trim((string)($_POST['helius_api_base'] ?? 'https://api-mainnet.helius-rpc.com')));
            flash('success', 'settings saved 🔑');
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
        if ($action === 'add_wallet') {
            $label = trim((string)($_POST['label'] ?? '')) ?: 'wallet';
            $address = trim((string)($_POST['address'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if (!validateSolanaAddress($address)) throw new RuntimeException('that does not look like a valid solana address bro 💀');
            $stmt = db()->prepare('INSERT INTO wallets (label,address,notes,created_at,updated_at) VALUES (:l,:a,:n,:c,:u)');
            $stmt->bindValue(':l', $label, SQLITE3_TEXT);
            $stmt->bindValue(':a', $address, SQLITE3_TEXT);
            $stmt->bindValue(':n', $notes, SQLITE3_TEXT);
            $stmt->bindValue(':c', nowIso(), SQLITE3_TEXT);
            $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
            $stmt->execute();
            flash('success', 'wallet added 🦍');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?wallet=' . db()->lastInsertRowID()); exit;
        }
        if ($action === 'sync_wallet') {
            $walletId = (int)($_POST['wallet_id'] ?? 0);
            $pages = max(1, min(50, (int)($_POST['pages'] ?? 10)));
            $limit = max(1, min(100, (int)($_POST['limit'] ?? 100)));
            $result = syncWallet($walletId, $pages, $limit);
            flash('success', 'synced ' . $result['saved'] . ' txs across ' . $result['pages'] . ' page(s) ⚡');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?wallet=' . $walletId); exit;
        }
        if ($action === 'update_tx') {
            $txId = (int)($_POST['tx_id'] ?? 0);
            $walletId = (int)($_POST['wallet_id'] ?? 0);
            $stmt = db()->prepare('UPDATE transactions SET manual_category=:mc, tax_treatment=:tt,
                usd_value_manual=:usd, basis_usd_manual=:basis, notes=:notes, updated_at=:u WHERE id=:id');
            $stmt->bindValue(':mc', trim((string)($_POST['manual_category'] ?? '')), SQLITE3_TEXT);
            $stmt->bindValue(':tt', trim((string)($_POST['tax_treatment'] ?? 'review')), SQLITE3_TEXT);
            $usdVal = trim((string)($_POST['usd_value_manual'] ?? ''));
            $basisVal = trim((string)($_POST['basis_usd_manual'] ?? ''));
            $stmt->bindValue(':usd', $usdVal === '' ? null : (float)$usdVal, $usdVal === '' ? SQLITE3_NULL : SQLITE3_FLOAT);
            $stmt->bindValue(':basis', $basisVal === '' ? null : (float)$basisVal, $basisVal === '' ? SQLITE3_NULL : SQLITE3_FLOAT);
            $stmt->bindValue(':notes', trim((string)($_POST['notes'] ?? '')), SQLITE3_TEXT);
            $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
            $stmt->bindValue(':id', $txId, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'tx updated ✅');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?wallet=' . $walletId . '&tx=' . $txId); exit;
        }
        if ($action === 'delete_wallet') {
            $walletId = (int)($_POST['wallet_id'] ?? 0);
            $db = db(); $db->exec('BEGIN');
            try {
                $stmt = $db->prepare('DELETE FROM transactions WHERE wallet_id = :id');
                $stmt->bindValue(':id', $walletId, SQLITE3_INTEGER); $stmt->execute();
                $stmt = $db->prepare('DELETE FROM wallets WHERE id = :id');
                $stmt->bindValue(':id', $walletId, SQLITE3_INTEGER); $stmt->execute();
                $db->exec('COMMIT');
            } catch (Throwable $inner) { $db->exec('ROLLBACK'); throw $inner; }
            flash('success', 'wallet deleted 🗑️');
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
    } catch (Throwable $e) {
        if ($action === 'sync_wallet' && ($wid = (int)($_POST['wallet_id'] ?? 0)) > 0) updateWalletSyncFailure($wid, $e->getMessage());
        flash('error', $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_POST['wallet_id']) ? '?wallet=' . (int)$_POST['wallet_id'] : ''));
        exit;
    }
}

$wallets = allWallets();
$view = trim((string)($_GET['view'] ?? 'transactions'));
$currentWalletId = isset($_GET['wallet']) ? (int)$_GET['wallet'] : ((int)($wallets[0]['id'] ?? 0));
$currentWallet = $currentWalletId ? getWallet($currentWalletId) : null;
$currentTxId = isset($_GET['tx']) ? (int)$_GET['tx'] : 0;
$currentTx = $currentTxId ? getTransactionById($currentTxId) : null;
$filters = ['q' => trim((string)($_GET['q'] ?? '')), 'category' => trim((string)($_GET['category'] ?? ''))];
$transactions = $currentWallet ? getTransactionsForWallet((int)$currentWallet['id'], $filters) : [];
$stats = $currentWallet ? walletStats((int)$currentWallet['id']) : ['tx_count'=>0,'sol_in'=>0,'sol_out'=>0,'reviewed'=>0,'manual_usd_total'=>0];
$flowAnalysis = ($view === 'flow' || $view === 'tax') && !empty($wallets) ? getFlowAnalysis($wallets) : null;
$sankeyData = $flowAnalysis ? getFlowSankeyData($wallets, $flowAnalysis) : null;

if (isset($_GET['export'])) {
    if ($_GET['export'] === 'csv' && $currentWallet) exportCsv($transactions, $currentWallet);
    if ($_GET['export'] === 'tax_summary' && !empty($wallets)) {
        $fa = getFlowAnalysis($wallets);
        exportTaxSummary($wallets, $fa);
    }
}

$flashes = $_SESSION['flashes'] ?? [];
unset($_SESSION['flashes']);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🔥 <?= h(APP_NAME) ?> — solana tax tracker for degens</title>
    <meta name="description" content="Track your Solana meme coin trades, visualize fund flow between wallets, and export CPA-ready tax reports.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;800;900&family=Space+Grotesk:wght@400;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #09090f;
            --panel: rgba(12,14,22,0.88);
            --panel-2: rgba(18,20,32,0.9);
            --line: rgba(255,255,255,0.08);
            --line-bright: rgba(255,255,255,0.18);
            --text: #eef4ff;
            --muted: #8b97b8;
            --green: #14F195;
            --red: #ff4d6a;
            --yellow: #f5c15d;
            --blue: #3B82F6;
            --accent: #3B82F6;
            --sol-gradient: linear-gradient(135deg, #3B82F6, #14F195);
            --glow-accent: 0 0 25px rgba(59,130,246,0.2);
            --glow-green: 0 0 30px rgba(20,241,149,0.2);
            --radius: 18px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { background: var(--bg); color: var(--text); font-family: 'Space Grotesk', sans-serif; min-height: 100vh; }
        body::before { content: ''; position: fixed; top: 0; left: 0; right: 0; height: 400px; background: radial-gradient(ellipse at 50% -20%, rgba(59,130,246,0.08) 0%, transparent 70%); pointer-events: none; z-index: 0; }
        a { color: var(--green); text-decoration: none; transition: .15s; }
        a:hover { color: #14F195; text-shadow: 0 0 12px rgba(20,241,149,0.4); }
        button, input, select, textarea { font: inherit; border-radius: 12px; border: 1px solid var(--line); background: rgba(10,10,20,0.8); color: var(--text); transition: .2s; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 15px rgba(59,130,246,0.15); }
        input, select, textarea { padding: 11px 14px; width: 100%; }
        textarea { min-height: 80px; resize: vertical; }
        button { cursor: pointer; padding: 11px 18px; background: rgba(16,18,28,0.9); border: 1px solid rgba(255,255,255,0.1); font-weight: 600; letter-spacing: .02em; }
        button:hover { transform: translateY(-2px); border-color: rgba(255,255,255,0.25); box-shadow: 0 0 20px rgba(59,130,246,0.1); }
        .btn-primary { background: rgba(16,18,28,0.9); border-color: rgba(255,255,255,0.15); }
        .btn-primary:hover { border-color: rgba(59,130,246,0.4); box-shadow: var(--glow-accent); }
        .btn-danger { background: linear-gradient(135deg, rgba(255,77,106,0.4), rgba(255,77,106,0.2)); border-color: rgba(255,77,106,0.3); }
        .btn-ghost { background: rgba(14,14,24,0.6); }

        /* Top Nav */
        .top-nav { position: sticky; top: 0; z-index: 100; display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; background: rgba(9,9,15,0.92); backdrop-filter: blur(20px); border-bottom: 1px solid var(--line); }
        .top-nav .logo { display: flex; align-items: center; gap: 10px; }
        .top-nav .logo h1 { font-family: 'Orbitron', sans-serif; font-size: 20px; font-weight: 900; background: var(--sol-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; letter-spacing: .06em; text-transform: uppercase; }
        .top-nav .logo .ver { font-size: 11px; padding: 3px 8px; border-radius: 99px; background: rgba(59,130,246,0.1); color: var(--blue); font-weight: 700; border: 1px solid rgba(59,130,246,0.2); }
        .nav-links { display: flex; gap: 6px; }
        .nav-links a { padding: 8px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; color: var(--muted); border: 1px solid transparent; transition: .2s; }
        .nav-links a:hover { color: var(--text); background: rgba(255,255,255,0.05); text-shadow: none; }
        .nav-links a.active { color: var(--green); background: rgba(20,241,149,0.08); border-color: rgba(20,241,149,0.2); }

        /* Layout */
        .layout { display: grid; grid-template-columns: 300px 1fr 400px; min-height: calc(100vh - 60px); gap: 16px; padding: 16px; position: relative; z-index: 1; }
        .layout.full-width { grid-template-columns: 300px 1fr; }
        .sidebar, .main, .detail { background: var(--panel); border: 1px solid var(--line); border-radius: 20px; backdrop-filter: blur(10px); overflow: hidden; }
        .sidebar { display: flex; flex-direction: column; }

        .pane-head { padding: 16px; border-bottom: 1px solid var(--line); background: rgba(255,255,255,0.02); }
        .pane-body { padding: 16px; }
        .stack { display: grid; gap: 12px; }
        .sub { color: var(--muted); font-size: 12px; line-height: 1.5; }
        .mono { font-family: 'JetBrains Mono', monospace; }

        /* Wallet List */
        .wallet-list { display: grid; gap: 8px; max-height: 34vh; overflow: auto; }
        .wallet-card { display: block; padding: 12px; border-radius: 14px; border: 1px solid var(--line); background: rgba(16,16,28,0.6); transition: .2s; }
        .wallet-card:hover { border-color: rgba(255,255,255,0.2); transform: translateX(3px); text-shadow: none; }
        .wallet-card.active { border-color: var(--green); background: rgba(20,241,149,0.05); box-shadow: var(--glow-green); }
        .wallet-label { font-weight: 700; font-size: 14px; }
        .wallet-address { color: var(--muted); font-size: 11px; margin-top: 3px; word-break: break-all; font-family: 'JetBrains Mono', monospace; }

        /* Metrics */
        .mini-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .metric { background: rgba(16,16,28,0.6); border: 1px solid var(--line); border-radius: 16px; padding: 14px; position: relative; overflow: hidden; }
        .metric::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: var(--sol-gradient); }
        .metric .label { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
        .metric .value { font-size: 22px; font-weight: 800; margin-top: 4px; }

        /* Main */
        .main-top { padding: 16px; border-bottom: 1px solid var(--line); display: grid; gap: 12px; }
        .toolbar { display: grid; grid-template-columns: 1.4fr .7fr auto auto; gap: 8px; }

        /* Tx List */
        .tx-list { max-height: calc(100vh - 300px); overflow: auto; padding: 14px; display: grid; gap: 8px; }
        .tx-row { display: grid; grid-template-columns: 140px 1fr 110px 110px 120px; gap: 10px; align-items: start; padding: 13px; border: 1px solid var(--line); background: rgba(16,16,28,0.5); border-radius: 16px; transition: .2s; }
        .tx-row:hover { border-color: rgba(255,255,255,0.15); background: rgba(59,130,246,0.03); transform: translateY(-1px); text-shadow: none; }
        .tx-row.active { border-color: var(--green); box-shadow: var(--glow-green); }
        .tx-row .sig { font-weight: 700; font-size: 13px; font-family: 'JetBrains Mono', monospace; }
        .desc { font-size: 13px; line-height: 1.45; color: var(--muted); }
        .amount.in { color: var(--green); font-weight: 700; }
        .amount.out { color: var(--red); font-weight: 700; }
        .muted { color: var(--muted); }
        .right { text-align: right; }

        /* Badges */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--line); background: rgba(10,10,20,0.7); font-size: 11px; font-weight: 600; color: var(--muted); letter-spacing: .02em; }
        .badge.blue { color: var(--blue); border-color: rgba(59,130,246,0.3); }
        .badge.green { color: var(--green); border-color: rgba(20,241,149,0.3); }
        .badge.red { color: var(--red); border-color: rgba(255,77,106,0.3); }
        .badge.yellow { color: var(--yellow); border-color: rgba(245,193,93,0.3); }
        .badge.white { color: var(--text); border-color: rgba(255,255,255,0.2); }

        /* Detail */
        .detail-wrap { display: flex; flex-direction: column; height: 100%; }
        .detail-scroll { padding: 16px; overflow: auto; max-height: calc(100vh - 78px); display: grid; gap: 14px; }
        .card { background: rgba(16,16,28,0.6); border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
        .card h3 { margin: 0 0 12px; font-size: 15px; font-weight: 700; }
        .field-grid { display: grid; gap: 10px; }
        .field-grid.two { grid-template-columns: repeat(2, 1fr); }
        .divider { height: 1px; background: var(--line); margin: 6px 0; }

        /* Flash */
        .flash-wrap { display: grid; gap: 6px; }
        .flash { padding: 10px 14px; border-radius: 12px; border: 1px solid var(--line); font-size: 13px; font-weight: 600; }
        .flash.success { border-color: rgba(20,241,149,0.3); color: #a8ffd8; background: rgba(20,241,149,0.06); }
        .flash.error { border-color: rgba(255,77,106,0.3); color: #ffd3d8; background: rgba(255,77,106,0.06); }
        .help { font-size: 12px; color: var(--muted); line-height: 1.5; }

        /* Flow view */
        .flow-container { grid-column: 2 / -1; min-height: calc(100vh - 120px); }
        #sankey-chart { width: 100%; min-height: 500px; background: rgba(16,16,28,0.4); border-radius: 16px; border: 1px solid var(--line); }
        .tax-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .tax-card { background: rgba(16,16,28,0.6); border: 1px solid var(--line); border-radius: 18px; padding: 20px; position: relative; overflow: hidden; text-align: center; }
        .tax-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
        .tax-card.self::before { background: var(--green); }
        .tax-card.income::before { background: var(--yellow); }
        .tax-card.disposal::before { background: var(--red); }
        .tax-card.swap::before { background: var(--blue); }
        .tax-card .tc-icon { font-size: 32px; margin-bottom: 8px; }
        .tax-card .tc-label { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
        .tax-card .tc-value { font-size: 28px; font-weight: 800; margin-top: 6px; }
        .tax-card .tc-sub { font-size: 12px; color: var(--muted); margin-top: 4px; }

        .balance-list { display: grid; gap: 8px; }
        .balance-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: rgba(16,16,28,0.5); border: 1px solid var(--line); border-radius: 12px; }
        .balance-row .bw-label { font-weight: 700; }
        .balance-row .bw-value { font-family: 'JetBrains Mono', monospace; font-weight: 700; }

        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.25); }

        @media (max-width: 1450px) {
            .layout { grid-template-columns: 280px 1fr; }
            .detail { grid-column: 1 / -1; }
            .detail-scroll { max-height: none; }
            .flow-container { grid-column: 1 / -1; }
        }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .toolbar, .mini-grid, .field-grid.two, .tx-row { grid-template-columns: 1fr; }
            .tx-list { max-height: none; }
            .nav-links { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<!-- Top Nav -->
<nav class="top-nav">
    <div class="logo">
        <h1>🔥 <?= h(APP_NAME) ?></h1>
        <span class="ver">v<?= h(APP_VERSION) ?></span>
    </div>
    <div class="nav-links">
        <a href="?<?= $currentWallet ? 'wallet=' . (int)$currentWallet['id'] . '&' : '' ?>view=transactions" class="<?= $view === 'transactions' ? 'active' : '' ?>">📋 Transactions</a>
        <a href="?view=flow" class="<?= $view === 'flow' ? 'active' : '' ?>">🌊 Flow View</a>
        <a href="?view=tax" class="<?= $view === 'tax' ? 'active' : '' ?>">📊 Tax Summary</a>
    </div>
</nav>

<div class="layout <?= in_array($view, ['flow','tax']) ? 'full-width' : '' ?>">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="pane-head">
            <div class="sub">helius-powered solana tax tracker for degens 🦍</div>
        </div>
        <div class="pane-body stack">
            <?php if ($flashes): ?>
                <div class="flash-wrap">
                    <?php foreach ($flashes as $flash): ?>
                        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>🔑 settings</h3>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="save_settings">
                    <div>
                        <label class="sub">helius api key</label>
                        <input type="password" name="helius_api_key" value="<?= h(heliusApiKey()) ?>" placeholder="paste your helius api key">
                    </div>
                    <div>
                        <label class="sub">api base url</label>
                        <input type="text" name="helius_api_base" value="<?= h(apiBaseUrl()) ?>">
                    </div>
                    <button class="btn-primary" type="submit">save settings 💾</button>
                </form>
            </div>

            <div class="card">
                <h3>🦍 add wallet</h3>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="add_wallet">
                    <input type="text" name="label" placeholder="wallet label (e.g. main phantom, burner)">
                    <textarea name="address" placeholder="paste solana wallet address" rows="2"></textarea>
                    <textarea name="notes" placeholder="notes (e.g. pumpfun wallet, cold storage)" rows="2"></textarea>
                    <button type="submit" class="btn-primary">add wallet 🚀</button>
                </form>
            </div>

            <div class="card">
                <h3>📂 wallets</h3>
                <div class="wallet-list">
                    <?php foreach ($wallets as $wallet): ?>
                        <a class="wallet-card <?= $currentWallet && (int)$currentWallet['id'] === (int)$wallet['id'] ? 'active' : '' ?>" href="?wallet=<?= (int)$wallet['id'] ?>&view=<?= h($view) ?>">
                            <div class="wallet-label"><?= h($wallet['label']) ?></div>
                            <div class="wallet-address"><?= h($wallet['address']) ?></div>
                            <div class="sub" style="margin-top:4px;">synced: <?= h($wallet['last_synced_at'] ?: 'never') ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$wallets): ?>
                        <div class="sub">no wallets yet. paste one above and start degen-ing 🚀</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </aside>

<?php if ($view === 'transactions'): ?>
    <!-- Main: Transaction List -->
    <main class="main">
        <div class="main-top">
            <?php if ($currentWallet): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <div>
                        <h2 style="font-size:18px;font-weight:800;margin:0;"><?= h($currentWallet['label']) ?></h2>
                        <div class="sub mono"><?= h($currentWallet['address']) ?></div>
                    </div>
                    <span class="badge <?= ($currentWallet['last_sync_status'] ?? '') === 'error' ? 'red' : 'green' ?>">
                        <?= h($currentWallet['last_sync_status'] ?: 'not synced') ?>
                    </span>
                </div>

                <div class="mini-grid">
                    <div class="metric"><div class="label">📊 transactions</div><div class="value"><?= number_format($stats['tx_count']) ?></div></div>
                    <div class="metric"><div class="label">✅ reviewed</div><div class="value"><?= number_format($stats['reviewed']) ?></div></div>
                    <div class="metric"><div class="label">💰 sol in</div><div class="value" style="color:var(--green)"><?= rtrim(rtrim(number_format($stats['sol_in'],9,'.',''),'0'),'.') ?></div></div>
                    <div class="metric"><div class="label">🔥 sol out</div><div class="value" style="color:var(--red)"><?= rtrim(rtrim(number_format($stats['sol_out'],9,'.',''),'0'),'.') ?></div></div>
                </div>

                <form method="post" class="toolbar">
                    <input type="hidden" name="action" value="sync_wallet">
                    <input type="hidden" name="wallet_id" value="<?= (int)$currentWallet['id'] ?>">
                    <input type="number" name="pages" min="1" max="50" value="10" placeholder="pages">
                    <input type="number" name="limit" min="1" max="100" value="100" placeholder="per page">
                    <button class="btn-primary" type="submit">⚡ sync</button>
                    <a href="?wallet=<?= (int)$currentWallet['id'] ?>&export=csv<?= $filters['category'] !== '' ? '&category=' . urlencode($filters['category']) : '' ?><?= $filters['q'] !== '' ? '&q=' . urlencode($filters['q']) : '' ?>"><button class="btn-ghost" type="button">🧾 export csv</button></a>
                </form>

                <form method="get" class="toolbar">
                    <input type="hidden" name="wallet" value="<?= (int)$currentWallet['id'] ?>">
                    <input type="hidden" name="view" value="transactions">
                    <input type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="🔍 search signatures, types, notes...">
                    <select name="category">
                        <option value="">all categories</option>
                        <option value="unreviewed" <?= $filters['category'] === 'unreviewed' ? 'selected' : '' ?>>unreviewed</option>
                        <option value="manual" <?= $filters['category'] === 'manual' ? 'selected' : '' ?>>manual only</option>
                        <?php foreach (categoryOptions() as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $filters['category'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">filter</button>
                    <a href="?wallet=<?= (int)$currentWallet['id'] ?>&view=transactions"><button type="button" class="btn-ghost">reset</button></a>
                </form>
            <?php else: ?>
                <div class="pane-body">
                    <h2 style="margin:0 0 6px;">welcome to degen-taxes 🔥</h2>
                    <div class="sub">paste a wallet on the left, drop in your helius key, and start tracking your degen trades.</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="tx-list">
            <?php foreach ($transactions as $tx): ?>
                <a class="tx-row <?= $currentTx && (int)$currentTx['id'] === (int)$tx['id'] ? 'active' : '' ?>" href="?wallet=<?= (int)$currentWallet['id'] ?>&tx=<?= (int)$tx['id'] ?>&q=<?= urlencode($filters['q']) ?>&category=<?= urlencode($filters['category']) ?>&view=transactions">
                    <div>
                        <div class="sig"><?= h(shortSig($tx['signature'])) ?></div>
                        <div class="sub"><?= h(formatBlockTime((int)$tx['block_time'])) ?></div>
                    </div>
                    <div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                            <span class="badge blue"><?= h($tx['tx_type'] ?: 'unknown') ?></span>
                            <span class="badge yellow"><?= h(effectiveCategory($tx)) ?></span>
                            <?php if (($tx['tax_treatment'] ?? 'review') !== 'review'): ?>
                                <span class="badge green"><?= h($tx['tax_treatment']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="desc"><?= h($tx['description'] ?: '(no description)') ?></div>
                        <?php $ts = tokenSummary($tx); if ($ts !== ''): ?>
                            <div class="sub" style="margin-top:4px;"><?= h($ts) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="right"><div class="amount in">+<?= rtrim(rtrim(number_format((float)$tx['native_in_sol'],9,'.',''),'0'),'.') ?: '0' ?></div><div class="sub">SOL in</div></div>
                    <div class="right"><div class="amount out">-<?= rtrim(rtrim(number_format((float)$tx['native_out_sol'],9,'.',''),'0'),'.') ?: '0' ?></div><div class="sub">SOL out</div></div>
                    <div class="right"><div><?= $tx['usd_value_manual'] !== null ? '$' . number_format((float)$tx['usd_value_manual'],2) : '-' ?></div><div class="sub">manual usd</div></div>
                </a>
            <?php endforeach; ?>
            <?php if ($currentWallet && !$transactions): ?>
                <div class="card"><h3>no transactions yet 📭</h3><div class="sub">hit ⚡ sync above to pull helius enhanced transaction history.</div></div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Detail Panel -->
    <aside class="detail">
        <div class="detail-wrap">
            <div class="pane-head">
                <h3 style="margin:0;">🔍 transaction detail</h3>
                <div class="sub">review, classify, add manual usd, and prep for your CPA</div>
            </div>
            <div class="detail-scroll">
                <?php if ($currentTx && $currentWallet): ?>
                    <?php $transferRows = getTransferRows((int)$currentTx['id']); ?>
                    <div class="card">
                        <h3 class="mono"><?= h(shortSig($currentTx['signature'], 16, 14)) ?></h3>
                        <div class="field-grid two">
                            <div><div class="sub">time</div><div><?= h(formatBlockTime((int)$currentTx['block_time'], true)) ?></div></div>
                            <div><div class="sub">type</div><div><span class="badge blue"><?= h($currentTx['tx_type']) ?></span></div></div>
                            <div><div class="sub">source</div><div><?= h($currentTx['source'] ?: '-') ?></div></div>
                            <div><div class="sub">fee</div><div><?= number_format(((int)$currentTx['fee_lamports'])/1e9, 9) ?> SOL</div></div>
                        </div>
                        <div class="divider"></div>
                        <div class="desc"><?= h($currentTx['description'] ?: '(no description)') ?></div>
                    </div>

                    <div class="card">
                        <h3>↔️ transfers</h3>
                        <?php if ($transferRows): ?>
                            <div class="stack">
                                <?php foreach ($transferRows as $row): ?>
                                    <div style="padding:8px 0;border-bottom:1px solid var(--line);">
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;">
                                            <span class="badge <?= $row['direction'] === 'in' ? 'green' : 'red' ?>"><?= h($row['direction']) ?></span>
                                            <span class="badge"><?= h($row['transfer_kind']) ?></span>
                                            <span class="badge blue"><?= h($row['symbol'] ?: $row['mint'] ?: '-') ?></span>
                                        </div>
                                        <div><strong><?= rtrim(rtrim(number_format((float)$row['amount'],9,'.',''),'0'),'.') ?></strong></div>
                                        <div class="sub mono">from: <?= h($row['from_account'] ?: '-') ?></div>
                                        <div class="sub mono">to: <?= h($row['to_account'] ?: '-') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="sub">no parsed transfer rows for this tx.</div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <h3>🧾 manual tax review</h3>
                        <form method="post" class="stack">
                            <input type="hidden" name="action" value="update_tx">
                            <input type="hidden" name="tx_id" value="<?= (int)$currentTx['id'] ?>">
                            <input type="hidden" name="wallet_id" value="<?= (int)$currentWallet['id'] ?>">
                            <div class="field-grid two">
                                <div>
                                    <label class="sub">manual category</label>
                                    <select name="manual_category">
                                        <option value="">use inferred: <?= h($currentTx['inferred_category']) ?></option>
                                        <?php foreach (categoryOptions() as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= ($currentTx['manual_category'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="sub">tax treatment</label>
                                    <select name="tax_treatment">
                                        <?php foreach (taxTreatmentOptions() as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= ($currentTx['tax_treatment'] ?? 'review') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="sub">manual usd value</label>
                                    <input type="number" step="0.00000001" name="usd_value_manual" value="<?= h($currentTx['usd_value_manual'] !== null ? (string)$currentTx['usd_value_manual'] : '') ?>" placeholder="usd at receipt / disposal">
                                </div>
                                <div>
                                    <label class="sub">manual cost basis usd</label>
                                    <input type="number" step="0.00000001" name="basis_usd_manual" value="<?= h($currentTx['basis_usd_manual'] !== null ? (string)$currentTx['basis_usd_manual'] : '') ?>" placeholder="cost basis for disposal">
                                </div>
                            </div>
                            <div>
                                <label class="sub">notes</label>
                                <textarea name="notes" placeholder="e.g. self transfer to cold wallet / airdrop claim / pumpfun rugpull proceeds"><?= h($currentTx['notes'] ?? '') ?></textarea>
                            </div>
                            <button class="btn-primary" type="submit">save review ✅</button>
                        </form>
                        <div class="help" style="margin-top:10px;">
                            💡 pro tip: tag fee claims as income, your own wallet moves as non-taxable transfer, rug proceeds as disposal, and buy-backs as swap.
                        </div>
                    </div>

                    <div class="card">
                        <h3>📜 raw json</h3>
                        <pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:11px;line-height:1.4;color:#9db0d0;font-family:'JetBrains Mono',monospace;max-height:300px;overflow:auto;"><?= h((string)$currentTx['raw_json']) ?></pre>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <h3>how to use degen-taxes 🦍</h3>
                        <div class="help">
                            1. paste your wallet(s) on the left<br>
                            2. save your helius api key<br>
                            3. hit ⚡ sync on each wallet<br>
                            4. click transactions to classify them<br>
                            5. switch to 🌊 flow view to see where the money went<br>
                            6. check 📊 tax summary for your tax buckets<br>
                            7. export and hand it to your CPA 🧾
                        </div>
                    </div>
                    <div class="card">
                        <h3>⚠️ reality check</h3>
                        <div class="help">this app helps you organize the chaos. it does NOT calculate authoritative USD basis. use manual usd fields or extend with a pricing API. this is your starting point, not your final tax return. NFA. 🫡</div>
                    </div>
                    <?php if ($currentWallet): ?>
                        <div class="card">
                            <h3>🗑️ delete wallet</h3>
                            <form method="post" onsubmit="return confirm('delete this wallet and all stored transactions? no take-backs 💀');">
                                <input type="hidden" name="action" value="delete_wallet">
                                <input type="hidden" name="wallet_id" value="<?= (int)$currentWallet['id'] ?>">
                                <button class="btn-danger" type="submit">delete wallet + all data</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>

<?php elseif ($view === 'flow'): ?>
    <!-- Flow View -->
    <div class="flow-container main" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div>
                <h2 style="margin:0;font-size:20px;">🌊 fund flow visualization</h2>
                <div class="sub">see how SOL moved between your wallets and external addresses</div>
            </div>
            <a href="?export=tax_summary"><button class="btn-primary">📥 export flow data</button></a>
        </div>

        <?php if (empty($wallets)): ?>
            <div class="card"><h3>no wallets yet 📭</h3><div class="sub">add some wallets and sync them first to see the flow.</div></div>
        <?php elseif ($sankeyData && !empty($sankeyData['nodes'])): ?>
            <div id="sankey-chart"></div>
            <div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <div class="badge green">🔄 self-transfer (non-taxable)</div>
                <div class="badge yellow">📥 income (ordinary income)</div>
                <div class="badge red">📤 outflow (potential disposal)</div>
            </div>
        <?php else: ?>
            <div class="card"><h3>no flow data yet 📊</h3><div class="sub">sync your wallets first — once transactions are pulled, the flow chart will appear here showing how SOL moved.</div></div>
        <?php endif; ?>
    </div>

<?php elseif ($view === 'tax'): ?>
    <!-- Tax Summary View -->
    <div class="flow-container main" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div>
                <h2 style="margin:0;font-size:20px;">📊 tax summary dashboard</h2>
                <div class="sub">your transactions broken down by tax bucket</div>
            </div>
            <a href="?export=tax_summary"><button class="btn-primary">📥 export tax summary CSV</button></a>
        </div>

        <?php if ($flowAnalysis): ?>
            <div class="tax-grid" style="margin-bottom:20px;">
                <div class="tax-card self">
                    <div class="tc-icon">🔄</div>
                    <div class="tc-label">self-transfers</div>
                    <div class="tc-value" style="color:var(--green);"><?= rtrim(rtrim(number_format($flowAnalysis['self_transfers_sol'],4,'.',''),'0'),'.') ?> SOL</div>
                    <div class="tc-sub">wallet ↔ wallet — not taxed</div>
                </div>
                <div class="tax-card income">
                    <div class="tc-icon">📥</div>
                    <div class="tc-label">income / claims</div>
                    <div class="tc-value" style="color:var(--yellow);"><?= rtrim(rtrim(number_format($flowAnalysis['income_sol'],4,'.',''),'0'),'.') ?> SOL</div>
                    <div class="tc-sub">ordinary income bracket</div>
                </div>
                <div class="tax-card disposal">
                    <div class="tc-icon">📤</div>
                    <div class="tc-label">outflows / disposals</div>
                    <div class="tc-value" style="color:var(--red);"><?= rtrim(rtrim(number_format($flowAnalysis['disposal_sol'],4,'.',''),'0'),'.') ?> SOL</div>
                    <div class="tc-sub">capital gains events</div>
                </div>
                <div class="tax-card swap">
                    <div class="tc-icon">🔀</div>
                    <div class="tc-label">swaps detected</div>
                    <div class="tc-value" style="color:var(--blue);"><?= number_format($flowAnalysis['swap_count']) ?></div>
                    <div class="tc-sub">taxable swap transactions</div>
                </div>
            </div>

            <div class="card" style="margin-bottom:16px;">
                <h3>🏦 final wallet balances (net SOL flow)</h3>
                <div class="balance-list">
                    <?php foreach ($flowAnalysis['final_balances'] as $label => $net): ?>
                        <div class="balance-row">
                            <div class="bw-label"><?= h($label) ?></div>
                            <div class="bw-value" style="color:<?= $net >= 0 ? 'var(--green)' : 'var(--red)' ?>;">
                                <?= $net >= 0 ? '+' : '' ?><?= rtrim(rtrim(number_format($net,6,'.',''),'0'),'.') ?> SOL
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3>⚠️ tax disclaimer</h3>
                <div class="help">
                    these numbers are based on on-chain heuristics and your manually registered wallets. they are estimates to help you organize, NOT authoritative tax calculations.
                    always consult a CPA or qualified tax professional. USD basis is not calculated automatically — use the manual fields or extend with a pricing source. NFA. DYOR. 🫡
                </div>
            </div>
        <?php else: ?>
            <div class="card"><h3>no data yet 📊</h3><div class="sub">add wallets and sync them to generate your tax summary.</div></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

<?php if ($view === 'flow' && $sankeyData && !empty($sankeyData['nodes'])): ?>
<script src="https://d3js.org/d3.v7.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/d3-sankey@0.12.3/dist/d3-sankey.min.js"></script>
<script>
(function() {
    const data = <?= json_encode($sankeyData, JSON_UNESCAPED_SLASHES) ?>;
    const container = document.getElementById('sankey-chart');
    const width = container.clientWidth;
    const height = Math.max(500, data.nodes.length * 60);
    container.style.height = height + 'px';

    const svg = d3.select('#sankey-chart').append('svg')
        .attr('width', width).attr('height', height);

    const sankey = d3.sankey()
        .nodeId(d => d.index)
        .nodeWidth(20)
        .nodePadding(18)
        .extent([[30, 20], [width - 30, height - 20]]);

    const graph = sankey({
        nodes: data.nodes.map(d => Object.assign({}, d)),
        links: data.links.map(d => Object.assign({}, d))
    });

    const colorMap = { self_transfer: '#14F195', income: '#f5c15d', disposal: '#ff4d6a' };

    svg.append('g').selectAll('rect').data(graph.nodes).join('rect')
        .attr('x', d => d.x0).attr('y', d => d.y0)
        .attr('height', d => Math.max(d.y1 - d.y0, 4))
        .attr('width', sankey.nodeWidth())
        .attr('fill', '#3B82F6').attr('rx', 4)
        .append('title').text(d => d.name);

    svg.append('g').attr('fill', 'none').selectAll('path')
        .data(graph.links).join('path')
        .attr('d', d3.sankeyLinkHorizontal())
        .attr('stroke', d => colorMap[d.type] || '#555')
        .attr('stroke-opacity', 0.45)
        .attr('stroke-width', d => Math.max(2, d.width))
        .on('mouseover', function() { d3.select(this).attr('stroke-opacity', 0.8); })
        .on('mouseout', function() { d3.select(this).attr('stroke-opacity', 0.45); })
        .append('title').text(d => `${graph.nodes[d.source.index]?.name || '?'} → ${graph.nodes[d.target.index]?.name || '?'}\n${d.value} SOL\n${d.type.replace('_',' ')}`);

    svg.append('g').selectAll('text').data(graph.nodes).join('text')
        .attr('x', d => d.x0 < width / 2 ? d.x1 + 8 : d.x0 - 8)
        .attr('y', d => (d.y0 + d.y1) / 2)
        .attr('dy', '0.35em')
        .attr('text-anchor', d => d.x0 < width / 2 ? 'start' : 'end')
        .attr('fill', '#eef4ff')
        .attr('font-size', '13px')
        .attr('font-family', 'Space Grotesk, sans-serif')
        .attr('font-weight', '600')
        .text(d => d.name);
})();
</script>
<?php endif; ?>

</body>
</html>
