"""Free Lunch Hunter MCP server.

Layers, outermost first: `server` (MCP protocol) → `usecases` (intent-level operations)
→ `domain` (models and rules, no I/O) ← `client` (HTTP adapter implementing the use-case port).
"""
