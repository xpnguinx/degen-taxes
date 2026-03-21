# 🔥 DEGEN-TAXES
<img width="1169" height="683" alt="Screenshot 2026-03-21 at 4 19 20 PM" src="https://github.com/user-attachments/assets/144b5c64-351f-4354-b50d-26f7cbb702e2" />
<img width="1229" height="654" alt="Screenshot 2026-03-21 at 4 27 31 PM" src="https://github.com/user-attachments/assets/206f74cc-103b-44d3-afff-86c42572b37e" />

> **Solana meme coin tax tracker for degens who actually want to stay out of jail.**

A single-file PHP app that pulls your Solana wallet transaction history via the [Helius API](https://helius.dev), classifies transactions into tax buckets, visualizes fund flow between wallets, and exports CPA-ready reports.

Built for the meme coin trader lifestyle — paste your wallets, sync your history, see where the money went, and figure out what you owe before the IRS figures it out for you.

---

## ✨ Features

- **🦍 Multi-Wallet Management** — Add all your Phantom wallets, burner wallets, and fee claim wallets
- **⚡ Helius API Sync** — Pull enhanced transaction history with one click
- **🧾 Auto-Classification** — Transactions are auto-categorized as self-transfers, income, swaps, disposals, etc.
- **🌊 Flow Visualization** — Interactive Sankey diagram showing how SOL moved between your wallets
- **📊 Tax Summary Dashboard** — See totals by tax bucket: non-taxable transfers, ordinary income, capital gains
- **📥 CSV Export** — Per-bucket or combined "CPA-ready" export
- **🔥 Degen-Friendly UI** — Dark neon Solana theme, emoji copy, built for traders not accountants

---

## 📋 Requirements

- **PHP 8.1+** (tested on 8.5)
- **SQLite3 extension** (usually bundled with PHP)
- **cURL extension** (usually bundled with PHP)
- A [Helius API key](https://helius.dev) (free tier works fine)

---

## 🚀 Getting Started

### 1. Clone the repo

```bash
git clone https://github.com/xpnguinx/degen-taxes.git
cd degen-taxes
```

### 2. Create your `.env` file

```bash
cp .env .env.backup   # optional
```

Open `.env` and paste your Helius API key:

```
HELIUS_API_KEY=your_actual_helius_api_key
HELIUS_API_BASE=https://api-mainnet.helius-rpc.com
```

> 💡 Get a free Helius API key at [helius.dev](https://helius.dev)

### 3. Start the server

```bash
php -S localhost:8000
```

### 4. Open in browser

```
http://localhost:8000
```

That's it. No npm, no build step, no Docker. Just PHP.

---

## 🎯 How to Use

### Step 1: Add Your Wallets
Paste your Solana wallet addresses in the sidebar — your main Phantom wallet, burner wallets, fee claim wallets, wherever SOL touches.

### Step 2: Save Your API Key
Enter your Helius API key in the settings section and hit save. You can also set it in the `.env` file and it'll auto-load.

### Step 3: Sync Transactions
Click **⚡ sync** on each wallet. The app pulls enhanced transaction data from Helius including native SOL transfers, token transfers, swap details, and program interactions.

### Step 4: Review & Classify
Click on individual transactions to review them. Set manual categories and tax treatments:
- **Self-transfer** → moving between your own wallets (not taxed)
- **Income receipt** → airdrops, staking rewards, fee claims (ordinary income)
- **Swap** → token swaps on DEXs (taxable event)
- **Disposal** → selling for USDC/USD (capital gains)

### Step 5: View the Flow
Switch to **🌊 Flow View** to see an interactive Sankey diagram of how SOL moved between your wallets and external addresses.

### Step 6: Check Tax Summary
The **📊 Tax Summary** view breaks everything into buckets with totals, so you know roughly what's taxable and what isn't.

### Step 7: Export
Download CSV files — per-wallet ledger or a combined tax summary — and hand them to your CPA.

---

## 🛠 Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | PHP 8.1+ (single file, no framework) |
| Database | SQLite (zero-config, local storage) |
| API | Helius Enhanced Transactions |
| Visualization | D3.js + d3-sankey (CDN) |
| Fonts | Orbitron, Space Grotesk, JetBrains Mono |
| Styling | Vanilla CSS, dark theme |

---

## ⚠️ DISCLAIMER — PLEASE READ

> **THIS APPLICATION IS NOT FINANCIAL, TAX, OR LEGAL ADVICE.**
>
> degen-taxes is a **tool for organizing on-chain transaction data**. It is provided "as-is" for informational and organizational purposes only.
>
> - This app does **NOT** calculate authoritative USD cost basis or historical fair market values
> - Transaction classifications are based on **heuristics and best guesses** — they may be wrong
> - Tax laws vary by jurisdiction and change frequently — **what applies to you may differ**
> - The tax buckets and summaries shown are **rough estimates**, not final tax calculations
> - You should **always double-check** all classifications, amounts, and calculations yourself
> - **Always consult a qualified CPA, tax professional, or tax attorney** before filing
> - The creators and contributors of this project are **not responsible** for any errors, omissions, financial losses, penalties, or legal consequences resulting from the use of this software
> - By using this app, you acknowledge that you are **solely responsible** for your own tax reporting and compliance
>
> **TL;DR: This is a bookkeeping helper, not a tax calculator. DYOR. NFA. Always verify with a professional. 🫡**

---

## 📄 License

MIT — Do whatever you want with it. Not financial advice. Not legal advice. DYOR.

---

Built with ☕ and 🔥 by [@xpnguinx](https://github.com/xpnguinx)
