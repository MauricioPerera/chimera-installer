# Chimera PHP Installer

WordPress-style installer for Chimera PHP Agent.

## Installation

1. Upload this folder to your web hosting (FTP, cPanel file manager, etc.)
2. Open `https://yourdomain.com/chimera-installer/index.php` in your browser
3. Enter your Cloudflare credentials (free account)
4. Click "Install"
5. Start chatting at `https://yourdomain.com/chimera-installer/chimera/chat.php`

## What You Need

- PHP 8.1+ hosting (any shared hosting with PHP works)
- Cloudflare account (free) for AI models:
  - Go to [dash.cloudflare.com](https://dash.cloudflare.com)
  - Get your Account ID (sidebar → Settings)
  - Create an API Token (My Profile → API Tokens → Workers AI template)

## After Installation

- **Chat UI**: `/chimera/chat.php`
- **API**: `/chimera/api.php?action=chat` (POST with `{"message": "..."}`)
- **Stats**: `/chimera/api.php?action=stats`
- **Dream**: `/chimera/api.php?action=dream` (consolidate memory)
- **Delete** `index.php` after setup (security)

## API Usage

```bash
curl -X POST https://yourdomain.com/chimera/api.php?action=chat \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"message": "Hello, what can you do?"}'
```

## Included Packages

- chimera-php (autonomous agent with learning loop)
- php-agent-memory (persistent memory + dream consolidation)
- php-agent-shell (CLI command execution)
- php-a2e (declarative workflow execution)
- php-vector-store (vector database)

All zero external dependencies. Pure PHP.
