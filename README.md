# AI Chatbot for WooCommerce

AI-powered product search for WooCommerce stores. Lets customers find products using natural language, with intelligent LLM-based relevance ranking, multi-language support, and multi-currency conversion.

[![Version](https://img.shields.io/badge/version-8.0.8-blue.svg)](https://chatbotagent.biz)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-required-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPLv2-green.svg)](LICENSE)

---

## Features

- **AI-powered search** — customers describe what they want in natural language
- **Smart ranking** — LLM-based relevance scoring filters out irrelevant products
- **Multi-language** — auto-detects 9+ languages (EN, RU, ES, DE, FR, JA, KO, TH, HE)
- **Multi-currency** — WOOCS, WMC support with real-time conversion
- **Order status** — customers check order and delivery status right in the chat
- **Product comparison** — side-by-side comparison of two products
- **SKU search** — instant local lookup by SKU or article number
- **Customizable design** — colors, fonts, position, dark mode, glass effect
- **GDPR-ready** — consent banner, hashed analytics, auto-delete old logs
- **Analytics** — see what customers search for, find gaps in your catalog
- **Vertical attributes** — material, colors, stitches, difficulty, sizes
- **Self-hosted updates** — updates appear automatically in WordPress admin

---

## Requirements

- WordPress **6.2** or higher
- WooCommerce (active)
- PHP **7.4** or higher
- Active license key from [chatbotagent.biz](https://chatbotagent.biz)
- cURL extension
- JSON extension

---

## Installation

### From WordPress Admin

1. Download the latest `ai-chatbot-for-woocommerce.zip` from [Releases](../../releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin
5. Go to **AI Chatbot → License** and enter your license key
6. Go to **AI Chatbot → Index** and click **Start** to index your products
7. Configure design and settings to your liking

### Manual Installation (via FTP / SSH)

```bash
cd wp-content/plugins/
git clone https://github.com/YOUR-USERNAME/ai-chatbot-for-woocommerce.git