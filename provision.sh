#!/bin/bash
# provision.sh — Sovereign Builder dev environment setup
# Run via: docker exec sb_wpcli bash /provision.sh
# Idempotent — safe to run multiple times

set -e

echo "=== Sovereign Builder Provision Script ==="
echo "Starting at $(date)"

WP_URL="http://localhost:8080"
WP_TITLE="Sovereign Builder Dev"
WP_ADMIN="admin"
WP_PASS="sovereign_admin"
WP_EMAIL="greg@grianna.com"
PLUGIN_SLUG="sovereign-builder"

# ── Wait for WordPress to be reachable ───────────────────────────────────────

echo ""
echo "--- Waiting for WordPress..."
until wp core is-installed --allow-root 2>/dev/null; do
  echo "    WordPress not ready, waiting 5s..."
  sleep 5
done
echo "    WordPress is ready."

# ── Install WordPress if not already installed ────────────────────────────────

echo ""
echo "--- Checking WordPress installation..."
if ! wp core is-installed --allow-root; then
  echo "    Installing WordPress..."
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN" \
    --admin_password="$WP_PASS" \
    --admin_email="$WP_EMAIL" \
    --allow-root
  echo "    WordPress installed."
else
  echo "    WordPress already installed, skipping."
fi

# ── Activate Sovereign Builder ────────────────────────────────────────────────

echo ""
echo "--- Activating Sovereign Builder plugin..."
if wp plugin is-active "$PLUGIN_SLUG" --allow-root; then
  echo "    Plugin already active."
else
  wp plugin activate "$PLUGIN_SLUG" --allow-root
  echo "    Plugin activated."
fi

# ── Verify table count ────────────────────────────────────────────────────────

echo ""
echo "--- Verifying database tables..."
TABLE_COUNT=$(wp db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'sovereign_wp' AND table_name LIKE 'wp_sb_%';" --allow-root --skip-column-names 2>/dev/null || echo "0")
echo "    Found $TABLE_COUNT Sovereign Builder tables."
if [ "$TABLE_COUNT" -lt 60 ]; then
  echo "    WARNING: Expected 70+ tables, found $TABLE_COUNT."
  echo "    Running repair-system via WP-CLI..."
  wp eval "
    if (class_exists('SB_Installer')) {
      SB_Installer::create_tables();
      SB_Installer::create_capabilities();
      SB_Installer::schedule_cron();
      echo 'Repair complete.';
    } else {
      echo 'SB_Installer class not found.';
    }
  " --allow-root
else
  echo "    Table count OK."
fi

# ── Seed content ──────────────────────────────────────────────────────────────

echo ""
echo "--- Running content seeder..."
wp eval "
  if (class_exists('SB_Content_Seeder')) {
    SB_Content_Seeder::seed_all();
    echo 'Content seeded.';
  } else {
    echo 'SB_Content_Seeder not found.';
  }
" --allow-root

# ── Import blueprints from /blueprints directory ──────────────────────────────

echo ""
echo "--- Importing blueprints..."
if [ -d "/blueprints" ] && [ "$(ls -A /blueprints/*.json 2>/dev/null)" ]; then
  for blueprint_file in /blueprints/*.json; do
    blueprint_name=$(basename "$blueprint_file")
    echo "    Importing $blueprint_name..."
    wp eval "
      \$json = file_get_contents('$blueprint_file');
      if (class_exists('SB_Library_Importer')) {
        \$result = SB_Library_Importer::import_from_json(\$json);
        echo is_wp_error(\$result) ? 'ERROR: ' . \$result->get_error_message() : 'OK';
      } else {
        echo 'SB_Library_Importer not found.';
      }
    " --allow-root
  done
else
  echo "    No blueprint JSON files found in /blueprints — skipping."
fi

# ── Configure Sovereign Builder settings ─────────────────────────────────────

echo ""
echo "--- Configuring Sovereign Builder settings..."
wp eval "
  // Point Factory API at local Ollama instead of Anthropic
  update_option('sb_ai_provider', 'local_llm');
  update_option('sb_local_llm_endpoint', 'http://ollama:11434/api/generate');
  update_option('sb_local_llm_model', 'llama3');
  update_option('sb_from_name', 'Sovereign Builder Dev');
  update_option('sb_from_email', 'dev@sovereign.local');
  echo 'Settings configured.';
" --allow-root

# ── Pull Ollama model ─────────────────────────────────────────────────────────

echo ""
echo "--- Pulling Ollama llama3 model (this may take several minutes on first run)..."
curl -s -X POST http://ollama:11434/api/pull \
  -H "Content-Type: application/json" \
  -d '{"name": "llama3"}' \
  | grep -E '"status"|"error"' | tail -5 || echo "    Ollama pull returned — check Ollama container logs if issues."

# ── Final health check ────────────────────────────────────────────────────────

echo ""
echo "--- Running Sovereign Builder environment check..."
wp eval "
  if (class_exists('SB_Installer')) {
    \$health = SB_Installer::verify_environment();
    echo 'Healthy: ' . (\$health['healthy'] ? 'YES' : 'NO') . PHP_EOL;
    if (!empty(\$health['failures'])) {
      echo 'Failures:' . PHP_EOL;
      foreach (\$health['failures'] as \$f) { echo '  - ' . \$f . PHP_EOL; }
    }
    if (!empty(\$health['pass'])) {
      echo 'Passing checks: ' . count(\$health['pass']) . PHP_EOL;
    }
  }
" --allow-root

echo ""
echo "=== Provision complete at $(date) ==="
echo ""
echo "Access points:"
echo "  WordPress admin : http://localhost:8080/wp-admin"
echo "  Admin user      : $WP_ADMIN"
echo "  Admin password  : $WP_PASS"
echo "  PhpMyAdmin      : http://localhost:8081"
echo "  Ollama API      : http://localhost:11434"
echo ""
