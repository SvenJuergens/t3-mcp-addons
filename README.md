# MCP Addons for TYPO3

Additional tools for the [TYPO3 MCP server](https://github.com/hauptsacheNet/typo3-mcp-server)
(`hn/typo3-mcp-server`). The workspace tools work on the workspace the MCP user is
currently in, so drafts written through MCP can be reviewed and published
without opening the backend.

| Tool               | Purpose                                                                                                   |
|--------------------|-----------------------------------------------------------------------------------------------------------|
| `GetPreviewLink`   | Returns a workspace preview link (`ADMCMD_prev`) for a page, optionally for a specific language, plus the expiry date of the token. Warns when the preview will not show the page (hidden, start/end time, missing translation, unresolvable slug). |
| `PublishWorkspace` | Publishes all pending changes of the current workspace. Supports `dryRun` to list what would go live.      |
| `GetCurrentUser`   | Returns the backend user the MCP connection acts as: uid, username, name and email. Read-only, no parameters, only ever the current user. |

## Command

| Command                        | Purpose |
|--------------------------------|---------|
| `mcp-addons:workspace:publish` | Publishes every workspace whose publication date (`publish_time`) is set and lies in the past. Unlike the core command `workspace:autopublish`, the date is not reset afterwards, so the command can run on a schedule and publishes the current workspace content on every run. Setting a past date switches automatic publishing on, clearing it switches it off. Supports `--dry-run`. |

## Requirements

* TYPO3 13.4 or 14.3
* `typo3/cms-workspaces`
* `hn/typo3-mcp-server` 0.6

## Installation

```bash
composer require svenjuergens/t3-mcp-addons
```

The tools register themselves through the `mcp.tool` service tag of the MCP
server; no further configuration is needed. Flush the caches afterwards.

## Notes

* A preview token unlocks the whole workspace, not just the requested page.
  Tokens expire after 48 hours unless the workspace record or user TSconfig
  (`options.workspaces.previewLinkTTLHours`) says otherwise.
* `PublishWorkspace` cannot be undone through MCP. The tool description tells
  the model to call it only on an explicit request and to run a dry run first.
  Leave the extension out, or restrict the MCP user, where publishing should
  stay a manual review step.
* The same applies to `mcp-addons:workspace:publish`: schedule it only where
  workspace content may go live without a review.

## License

GPL-2.0-or-later
