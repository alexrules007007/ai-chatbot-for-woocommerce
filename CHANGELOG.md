# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [8.0.8] - 2026-09-26

### Added
- LLM-based relevance ranking for product search results
- Configurable relevance threshold (0-100) in Settings
- Multi-currency support (WOOCS, WMC)
- Product comparison tool
- Order status lookup in chat
- Delivery status lookup in chat
- GDPR consent banner
- Session tracking for analytics
- SKU search intercept (local, no API call)
- Dark mode for chat widget
- Glass effect overlay
- Analytics dashboard with top queries and empty results

### Fixed
- Products logged only when cards shown in reply
- Credit deduction: 1 request = 1 credit (LLM-based)

### Changed
- Improved system prompt with stricter invariants
- Better category matching against store catalog
- History truncation (last 6 messages, 300 chars each)

## [8.0.0] - 2026-08-15

### Added
- Initial public release
- AI-powered product search
- WooCommerce integration
- License activation system
- Design customization