# Dependencies

MCP Abilities - Broken Link Checker depends on four runtime systems. Each link goes to the authority for the named dependency.

- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) requires WordPress 6.9 or newer and registers the link-checking operations.
- [PHP 8.0 or newer](https://www.php.net/releases/8.0/en.php) provides the required server runtime.
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/) exposes the registered abilities to MCP clients.
- [Broken Link Checker](https://wordpress.org/plugins/broken-link-checker/) owns the local link data, scanner state, and repair records used by this add-on.

Install and activate Broken Link Checker and WordPress MCP Adapter before using this add-on through MCP.
