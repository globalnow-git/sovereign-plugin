# Sovereign Builder — Dev Environment

## Prerequisites
- Docker Desktop for Windows (Hyper-V backend)
- Git for Windows
- Windows 11

## First Time Setup

### 1. Clone the repo
```powershell
cd D:\
git clone https://github.com/globalnow-git/sovereign-plugin.git sovereign-dev
cd sovereign-dev
```

### 2. Create required directories
```powershell
mkdir data\mysql
mkdir data\wordpress
mkdir data\ollama
mkdir logs
mkdir plugin
mkdir blueprints
```

### 3. Add the plugin source
Copy `sovereign-builder-v2.6-complete.md` into `D:\sovereign-dev\plugin\`

The plugin directory is mounted directly into WordPress — no zip needed.

> **Note:** The plugin files must be extracted from the .md source before use.
> In a future session we will automate this via GitHub Actions.
> For now, extract manually or ask Claude to generate the extraction script.

### 4. Start the stack
```powershell
docker-compose up -d
```

Wait ~30 seconds for MySQL to initialize on first run.

### 5. Run provision
```powershell
docker exec sb_wpcli bash /provision.sh
```

This installs WordPress, activates Sovereign Builder, verifies tables, seeds content, imports any blueprints in /blueprints, and pulls the Ollama llama3 model.

**First run takes 5-10 minutes** — Ollama downloads ~4GB for llama3.

### 6. Verify
- WordPress admin: http://localhost:8080/wp-admin (admin / sovereign_admin)
- PhpMyAdmin: http://localhost:8081
- Ollama: http://localhost:11434

## Daily Use

### Start
```powershell
cd D:\sovereign-dev
docker-compose up -d
```

### Stop
```powershell
docker-compose down
```

### View WordPress debug log
```powershell
docker exec sb_wordpress tail -f /var/www/html/wp-content/debug-logs/debug.log
```

### Run WP-CLI commands
```powershell
docker exec sb_wpcli wp [command] --allow-root
```

### Re-run provision (safe, idempotent)
```powershell
docker exec sb_wpcli bash /provision.sh
```

## Switching AI Provider

**Local dev (Ollama — no API cost):**
Set in WordPress: Sovereign Builder → Settings → AI Provider → Local LLM

**Production (Anthropic):**
Set in WordPress: Sovereign Builder → Settings → AI Provider → Anthropic
Enter your API key.

The provision script defaults to Ollama.

## Branch Strategy
```
main     — always installable, confirmed working
dev      — active development
feature/ — specific build work
```

Never commit directly to main. Merge from dev when confirmed working in Docker.
