# Contributing

## How to Contribute

1. Fork the repository
2. Create a branch: `git checkout -b feature/my-feature`
3. Commit: `git commit -m "Add my feature"`
4. Push: `git push origin feature/my-feature`
5. Open a Pull Request

## Coding Standards

- Follow WordPress Coding Standards for PHP
- Use tabs for indentation
- Prefix all functions with `wcai_`
- Prefix all options with `wcai_`
- Sanitize all input, escape all output
- Use `wp_unslash()` before sanitizing `$_POST` / `$_GET`

## Reporting Bugs

Open an issue with:
- Clear description
- Steps to reproduce
- Expected vs actual behavior
- WordPress / WooCommerce / PHP versions
- Relevant error log entries

## Pull Request Checklist

- [ ] Code follows WordPress standards
- [ ] Tested locally on WordPress 6.2+
- [ ] No PHP warnings/notices in debug.log
- [ ] Updated CHANGELOG.md
- [ ] Updated version number if applicable

## License

By contributing, you agree that your contributions will be licensed under GPLv2 or later.