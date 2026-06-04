# MCP Abilities - Broken Link Checker

Broken Link Checker abilities for MCP.

This plugin exposes controlled abilities for sites that use the local Broken Link Checker data tables.

## Abilities

- `blc/list-tables`
- `blc/list-broken-links`
- `blc/get-notification-settings`
- `blc/add-notification-recipient`
- `blc/replace-url-in-content`
- `blc/auto-fix-broken-links`
- `blc/clear-queue`
- `blc/delete-links`

## Requirements

- WordPress 6.9+
- PHP 8.0+
- Abilities API
- MCP Adapter
- Broken Link Checker

## Notes

The plugin is intended for authenticated MCP workflows. It does not replace editorial review: inspect the broken link, verify the replacement target, and then apply the smallest safe fix.
