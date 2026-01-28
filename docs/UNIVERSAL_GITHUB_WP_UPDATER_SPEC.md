# Universal GitHub-to-WordPress Continuous Plugin Updater

## Complete Architecture Analysis & Universal Plugin Specification

**Document Version:** 1.1.0
**Based on:** TC-Booking-Flow_NEXT v0.9.2 analysis
**Date:** 2026-01-28
**Repository:** github.com/LukaszKomarTC/github-wp-updater (pending)

---

## TABLE OF CONTENTS

1. [Repository Loop Architecture Summary](#1-repository-loop-architecture-summary)
2. [Detailed Component Analysis](#2-detailed-component-analysis)
3. [Universal Plugin Specification](#3-universal-plugin-specification)
   - 3.0 [Plugin Connection Model](#30-plugin-connection-model-how-users-connect-plugins-to-github)
   - 3.1 [Core Features (MVP)](#31-core-features-mvp)
   - 3.2 [Security Requirements](#32-security-requirements)
   - 3.3 [Packaging/Update Strategy](#33-packagingupdate-strategy)
   - 3.4 [Verification Strategy](#34-verification-strategy-phase-3)
4. [Implementation Schedule](#4-implementation-schedule)
5. [Appendix A: GitHub Workflow Template](#appendix-a-github-workflow-template-mvp)
6. [Appendix B: REST API Reference](#appendix-b-rest-api-reference-mvp)

---

## 1. REPOSITORY LOOP ARCHITECTURE SUMMARY

### 1.1 High-Level Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        AI FULL CODING LOOP ARCHITECTURE                       │
└─────────────────────────────────────────────────────────────────────────────┘

┌──────────────────┐    ┌──────────────────┐    ┌──────────────────────────────┐
│  1. CODE CHANGE  │───▶│  2. VERSION BUMP │───▶│  3. PUSH TO MAIN BRANCH      │
│                  │    │                  │    │                              │
│  AI/Developer    │    │  tc-booking-     │    │  git push origin main        │
│  modifies code   │    │  flow-next.php   │    │                              │
│                  │    │  line 5: Version │    │                              │
└──────────────────┘    └──────────────────┘    └──────────────────────────────┘
                                                              │
                                                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         GITHUB ACTIONS LAYER                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────┐   ┌─────────────────────────────────┐  │
│  │     auto-release.yml            │   │    publish-latest-zip.yml      │  │
│  │                                 │   │                                 │  │
│  │  Trigger: push to main          │   │  Trigger: push to main          │  │
│  │  (tc-booking-flow-next.php)    │   │  (any change)                   │  │
│  │                                 │   │                                 │  │
│  │  Steps:                         │   │  Steps:                         │  │
│  │  1. Extract version (regex)     │   │  1. Build latest.zip            │  │
│  │  2. Check if release exists     │   │  2. Create latest.json          │  │
│  │  3. Build plugin ZIP            │   │  3. Create plugin-full.txt      │  │
│  │  4. Create GitHub release       │   │  4. Upload via SFTP             │  │
│  │  5. Trigger WP update ──────────┼───┼──▶ (staging server)             │  │
│  │                                 │   │                                 │  │
│  └─────────────────────────────────┘   └─────────────────────────────────┘  │
│                    │                                                        │
└────────────────────┼────────────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       WORDPRESS SITE LAYER                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                 REST API ENDPOINT                                    │    │
│  │                                                                      │    │
│  │  POST /wp-json/tc-booking-flow/v1/refresh                           │    │
│  │                                                                      │    │
│  │  Authentication:                                                     │    │
│  │  ├─ Admin user with 'update_plugins' capability                     │    │
│  │  └─ Token: X-Update-Token header == TC_BF_UPDATE_TOKEN constant     │    │
│  │                                                                      │    │
│  │  Actions:                                                            │    │
│  │  1. Clear update_plugins transient                                  │    │
│  │  2. Clear PUC database entries                                      │    │
│  │  3. Force PUC to check GitHub                                       │    │
│  │  4. Force wp_update_plugins()                                       │    │
│  │  5. If auto_update=true: Plugin_Upgrader->upgrade()                 │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                    │                                                        │
│                    ▼                                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │           PLUGIN UPDATE CHECKER (PUC v5p6)                          │    │
│  │                                                                      │    │
│  │  Configuration:                                                      │    │
│  │  ├─ Repository: github.com/LukaszKomarTC/TC-Booking-Flow_NEXT       │    │
│  │  ├─ Strategy: Latest Release > Latest Tag > Branch                  │    │
│  │  └─ Assets: Release ZIP attachments enabled                         │    │
│  │                                                                      │    │
│  │  Periodic Checks:                                                    │    │
│  │  ├─ WP Cron: Every 12 hours                                         │    │
│  │  ├─ Admin pages: load-update-core.php, load-plugins.php             │    │
│  │  └─ Manual: Dashboard > Updates (every 60 seconds)                  │    │
│  │                                                                      │    │
│  │  Flow:                                                               │    │
│  │  1. GET api.github.com/repos/:user/:repo/releases/latest           │    │
│  │  2. Parse version from tag_name (strip 'v' prefix)                  │    │
│  │  3. Compare with installed version                                  │    │
│  │  4. Inject into site_transient_update_plugins                       │    │
│  │  5. WordPress shows update notification                             │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                    │                                                        │
│                    ▼                                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │           WORDPRESS CORE UPGRADER                                    │    │
│  │                                                                      │    │
│  │  1. Download ZIP from GitHub release asset                          │    │
│  │  2. Extract to /wp-content/upgrade/                                 │    │
│  │  3. Rename directory to match existing plugin folder                │    │
│  │  4. Replace plugin files                                            │    │
│  │  5. Re-activate plugin if needed                                    │    │
│  │                                                                      │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    VERIFICATION (Optional)                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Current Implementation: HTTP response code check in auto-release.yml      │
│                                                                             │
│  curl -s -w "\n%{http_code}" -X POST \                                     │
│    -H "X-Update-Token: $WP_UPDATE_TOKEN" \                                 │
│    "${WP_SITE_URL}/wp-json/tc-booking-flow/v1/refresh"                     │
│                                                                             │
│  Future: Playwright/WebFetch checks against live site                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Security Model Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SECURITY MODEL                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  GITHUB SIDE                           WORDPRESS SIDE                       │
│  ════════════                          ══════════════                       │
│                                                                             │
│  Secrets (Repository Settings):        wp-config.php:                       │
│  ├─ GITHUB_TOKEN (auto)                └─ TC_BF_UPDATE_TOKEN                │
│  ├─ WP_SITE_URL                                                             │
│  ├─ WP_UPDATE_TOKEN ─────────────────────────────────────▶ Must match      │
│  ├─ FTP_SERVER                                                              │
│  ├─ FTP_USERNAME                       Authentication:                      │
│  └─ FTP_PASSWORD                       ├─ Timing-safe comparison            │
│                                        │   (hash_equals)                    │
│  Environment: TCBF                     ├─ Header: X-Update-Token            │
│  (Required for release)                └─ Or param: token                   │
│                                                                             │
│  Permissions:                          Capabilities:                        │
│  └─ contents: write                    └─ update_plugins                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. DETAILED COMPONENT ANALYSIS

### 2.1 Version Bump Logic

**Location:** `tc-booking-flow-next.php`

```php
// Line 5 - Plugin Header (PRIMARY SOURCE OF TRUTH)
* Version: 0.9.2

// Line 12 - PHP Constant (Fallback/Runtime)
if ( ! defined('TC_BF_VERSION') ) define('TC_BF_VERSION','0.9.2');
```

**Extraction Method (used by workflows):**
```bash
VERSION=$(grep -oP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+" tc-booking-flow-next.php)
```

**Key Points:**
- Version follows SemVer: `MAJOR.MINOR.PATCH`
- Must be updated manually before push
- GitHub tag format: `v0.9.2` (PUC strips the "v" prefix)
- No automatic version bumping (intentional for control)

---

### 2.2 Release Creation/Push Automation

**File:** `.github/workflows/auto-release.yml` (lines 1-107)

**Trigger:**
```yaml
on:
  push:
    branches: [main]
    paths:
      - 'tc-booking-flow-next.php'
```

**Step-by-Step Flow:**

| Step | Action | Command/Code |
|------|--------|--------------|
| 1 | Checkout | `actions/checkout@v4` |
| 2 | Extract version | `grep -oP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+"` |
| 3 | Check release exists | `gh release view "v$VERSION"` |
| 4 | Build ZIP | `rsync` + `zip` (excludes .git, .github, *.json) |
| 5 | Create release | `gh release create "v$VERSION" --generate-notes` |
| 6 | Trigger WP update | `curl POST /wp-json/tc-booking-flow/v1/refresh` |

**ZIP Building Details:**
```bash
rsync -av \
  --exclude ".git" \
  --exclude ".github" \
  --exclude "$BUILD_DIR" \
  --exclude ".DS_Store" \
  --exclude "*.json" \
  --exclude "GF_EXPORT_*.json" \
  ./ "$BUILD_DIR/$PLUGIN_SLUG/"

zip -r "../$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
```

---

### 2.3 WordPress-Side Update Mechanism

**File:** `tc-booking-flow-next.php` (lines 19-130)

#### Plugin Update Checker Integration (lines 19-28):

```php
require_once TC_BF_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$tcBfUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/LukaszKomarTC/TC-Booking-Flow_NEXT/',
    __FILE__,
    'tc-booking-flow-next'
);
$tcBfUpdateChecker->getVcsApi()->enableReleaseAssets();
```

**How PUC Works:**

| Component | Location | Responsibility |
|-----------|----------|----------------|
| `GitHubApi.php` | `plugin-update-checker/Puc/v5p6/Vcs/` | GitHub API communication |
| `Scheduler.php` | `plugin-update-checker/Puc/v5p6/` | Periodic check scheduling |
| `UpdateChecker.php` | `plugin-update-checker/Puc/v5p6/` | Core update logic |

**Update Detection Strategy** (from `GitHubApi.php:358-374`):
1. **Latest Release** - `GET /repos/:user/:repo/releases/latest`
2. **Latest Tag** - Fallback if no releases
3. **Branch** - Final fallback (for dev builds)

**Scheduling** (from `Scheduler.php`):
- Default: Every 12 hours via WP Cron
- Accelerated checks on:
  - `load-update-core.php` (Dashboard > Updates): 60 seconds
  - `load-plugins.php` (Plugins page): 1 hour
  - After `upgrader_process_complete`: immediate

---

### 2.4 Force Update Mechanism

**File:** `tc-booking-flow-next.php` (lines 31-130)

**REST Endpoint Registration:**
```php
register_rest_route( 'tc-booking-flow/v1', '/refresh', array(
    'methods'  => 'POST',
    'callback' => 'tc_bf_force_refresh',
    'permission_callback' => function( $request ) {
        // Admin capability OR token authentication
        if ( current_user_can( 'update_plugins' ) ) return true;

        $token = $request->get_header( 'X-Update-Token' );
        if ( ! $token ) $token = $request->get_param( 'token' );

        if ( $token && defined( 'TC_BF_UPDATE_TOKEN' )
             && hash_equals( TC_BF_UPDATE_TOKEN, $token ) ) {
            return true;
        }
        return false;
    },
));
```

**Cache Clearing (lines 62-78):**
```php
// 1. Delete WordPress update transient
delete_site_transient('update_plugins');

// 2. Clean WordPress plugin cache
wp_clean_plugins_cache();

// 3. Remove PUC database entries (aggressive)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%puc%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%tc_bf%update%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient%update%'");

// 4. Force PUC check
$tcBfUpdateChecker->checkForUpdates();

// 5. Force WordPress check
wp_update_plugins();

// 6. Flush object cache
wp_cache_flush();
```

**Auto-Update (lines 87-127):**
```php
if ( $request->get_param('auto_update') ) {
    $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );

    $update_plugins = get_site_transient('update_plugins');
    if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
        $upgrader->upgrade( $plugin_file );

        // Re-activate if needed
        if ( ! is_plugin_active( $plugin_file ) ) {
            activate_plugin( $plugin_file );
        }
    }
}
```

---

### 2.5 Post-Deploy Verification

**Current Implementation:** HTTP response check in `auto-release.yml` (lines 75-106)

```yaml
- name: Trigger WordPress plugin update
  run: |
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
      -H "X-Update-Token: $WP_UPDATE_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"auto_update": true}' \
      "${WP_SITE_URL}/wp-json/tc-booking-flow/v1/refresh")

    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)

    if [ "$HTTP_CODE" -ge 200 ] && [ "$HTTP_CODE" -lt 300 ]; then
      echo "Plugin update triggered successfully"
    else
      echo "Failed to trigger plugin update (HTTP $HTTP_CODE)"
      exit 1
    fi
```

**Response Format:**
```json
{
  "status": "refreshed",
  "version": "0.9.2",
  "time": "2026-01-28 12:00:00",
  "updated": true,
  "new_version": "0.9.3",
  "upgrade_attempted": true,
  "upgrade_result": true
}
```

---

### 2.6 Staging/Alternative Update Channel

**File:** `.github/workflows/publish-latest-zip.yml`

**Purpose:** Provides a secondary update source via SFTP to staging server.

**Outputs:**
| File | Purpose |
|------|---------|
| `latest.zip` | Plugin package |
| `latest.json` | Update metadata (PUC-compatible) |
| `plugin-full.txt` | Full codebase snapshot for AI context |
| `.htaccess` | No-cache headers for metadata |

**latest.json Format:**
```json
{
  "name": "TC Booking Flow NEXT",
  "version": "0.9.2",
  "download_url": "https://staging.lukaszkomar.com/dev/tc-booking-flow-next/latest.zip",
  "requires": "5.0",
  "tested": "6.4",
  "last_updated": "2026-01-28T12:00:00Z",
  "sections": {
    "description": "WordPress plugin for booking flow integration",
    "changelog": "Build abc123 on 2026-01-28T12:00:00Z"
  }
}
```

---

### 2.7 File Reference Summary

| File | Lines | Responsibility |
|------|-------|----------------|
| `tc-booking-flow-next.php` | 1-201 | Main plugin, version, PUC init, REST endpoint |
| `.github/workflows/auto-release.yml` | 1-107 | GitHub release automation |
| `.github/workflows/publish-latest-zip.yml` | 1-224 | Staging server deployment |
| `plugin-update-checker/Puc/v5p6/Vcs/GitHubApi.php` | 1-468 | GitHub API integration |
| `plugin-update-checker/Puc/v5p6/Scheduler.php` | 1-301 | Update check scheduling |
| `plugin-update-checker/Puc/v5p6/UpdateChecker.php` | 1-1142 | Core update mechanism |

---

### 2.8 Assumptions & Dependencies

| Assumption | Rationale |
|------------|-----------|
| WP Cron is functional | PUC relies on cron for periodic checks |
| Server has outbound HTTPS | Required for GitHub API calls |
| GitHub releases are public | No auth token required for public repos |
| ZIP contains single root folder | WordPress upgrader expects this structure |
| Plugin folder name matches slug | PUC renames if necessary |

| Dependency | Version | Purpose |
|------------|---------|---------|
| WordPress | 5.0+ | Core functionality |
| PHP | 5.6.20+ | PUC minimum requirement |
| Plugin Update Checker | v5p6 | GitHub-to-WP update bridge |
| GitHub Actions | N/A | CI/CD automation |

---

## 3. UNIVERSAL PLUGIN SPECIFICATION

### 3.0 Plugin Connection Model (How Users Connect Plugins to GitHub)

This section explains how the Universal GitHub WP Updater allows users to connect ANY WordPress plugin to ANY GitHub repository for automated updates.

#### 3.0.1 Two Supported Use Cases

| Use Case | Description | Example |
|----------|-------------|---------|
| **A) Plugin Developer** | You own the plugin, want auto-updates from your GitHub repo | TC-Booking-Flow pattern |
| **B) Site Administrator** | You install 3rd-party plugins from GitHub, want updates | Managing plugins you don't own |

#### 3.0.2 Connection Approach: Central Manager Plugin

The Universal Updater acts as a **central manager** that handles GitHub connections for ANY installed plugin:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    GITHUB WP UPDATER (Manager Plugin)                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Manages updates for:                                                       │
│                                                                             │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐          │
│  │ Plugin A         │  │ Plugin B         │  │ Plugin C         │          │
│  │ (3rd party)      │  │ (your own)       │  │ (forked)         │          │
│  │                  │  │                  │  │                  │          │
│  │ ↔ github.com/    │  │ ↔ github.com/    │  │ ↔ github.com/    │          │
│  │   user/plugin-a  │  │   you/plugin-b   │  │   you/plugin-c   │          │
│  └──────────────────┘  └──────────────────┘  └──────────────────┘          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Key Benefits:**
- Works with ANY plugin (no modification needed to target plugins)
- Centralized control (one place to manage all GitHub connections)
- Flexible authentication (supports public + private repos)
- CI/CD ready (REST API for automated triggers)

#### 3.0.3 Admin UI: Connect a Plugin to GitHub

**Step-by-step connection wizard:**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Settings > GitHub Updater > Add Plugin Connection                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Step 1: Select WordPress Plugin                                            │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │ [▾] Select installed plugin...                                      │    │
│  │     ├─ Advanced Custom Fields (acf/acf.php)                         │    │
│  │     ├─ My Custom Plugin (my-plugin/my-plugin.php)                   │    │
│  │     ├─ WooCommerce Addon (wc-addon/wc-addon.php)                    │    │
│  │     └─ TC Booking Flow (tc-booking-flow-next/tc-booking-flow.php)   │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                             │
│  Selected: my-plugin/my-plugin.php                                          │
│  Current Version: 1.2.3                                                     │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  Step 2: Enter GitHub Repository                                            │
│                                                                             │
│  Repository URL:                                                            │
│  [https://github.com/mycompany/my-plugin____________________]              │
│                                                                             │
│  Or paste in format: owner/repo                                             │
│  [mycompany/my-plugin_______________________________________]              │
│                                                                             │
│  [🔍 Detect from plugin header]  ← Reads "Plugin URI" or "GitHub URI"      │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  Step 3: Authentication (for private repos)                                 │
│                                                                             │
│  ○ Public repository (no authentication needed)                             │
│  ● Private repository                                                       │
│      Token: [ghp_xxxxxxxxxxxxxxxxxxxx______________________]               │
│                                                                             │
│  [Test Connection]                                                          │
│                                                                             │
│  ✓ Connected! Latest release: v1.3.0 (newer than installed 1.2.3)          │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  Step 4: Update Settings                                                    │
│                                                                             │
│  Release Channel: [● Stable ○ Pre-release ○ Branch: main]                  │
│  Auto-update:     [☑ Enable automatic updates]                             │
│                                                                             │
│  [Cancel]                                      [Save Connection]            │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### 3.0.4 Connected Plugins Dashboard

**Main admin screen showing all managed plugins:**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Settings > GitHub Updater > Connected Plugins                    [+ Add]   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │ Plugin                 │ GitHub Repo          │ Version   │ Status  │    │
│  ├─────────────────────────────────────────────────────────────────────┤    │
│  │ My Custom Plugin       │ mycompany/my-plugin  │ 1.2.3     │ ✓ Up to │    │
│  │ my-plugin/my-plugin.php│                      │           │   date  │    │
│  │                        │                      │           │ [Edit]  │    │
│  ├─────────────────────────────────────────────────────────────────────┤    │
│  │ TC Booking Flow        │ LukaszKomarTC/       │ 0.9.2 →   │ ⬆ Update│    │
│  │ tc-booking-flow/       │ TC-Booking-Flow_NEXT │ 0.9.3     │ available│   │
│  │ tc-booking-flow.php    │                      │           │ [Update]│    │
│  ├─────────────────────────────────────────────────────────────────────┤    │
│  │ ACF Pro (Fork)         │ mycompany/acf-fork   │ 6.2.0     │ ✓ Up to │    │
│  │ acf/acf.php            │ 🔒 Private           │           │   date  │    │
│  │                        │                      │           │ [Edit]  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                             │
│  [Check All Now]  [Bulk Actions ▾]                                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### 3.0.5 Auto-Detection Methods

To simplify setup, the plugin supports multiple detection methods:

**Method 1: Plugin Header Detection**

If a plugin includes GitHub info in its header, auto-detect is possible:

```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Plugin URI: https://github.com/user/my-awesome-plugin
 * GitHub Plugin URI: user/my-awesome-plugin
 * Version: 1.0.0
 */
```

Detection logic:
```php
$plugin_data = get_plugin_data( $plugin_file );
$github_uri = $plugin_data['GitHub Plugin URI'] ?? null;
// or parse from Plugin URI if it's a github.com URL
if ( ! $github_uri && strpos( $plugin_data['PluginURI'], 'github.com' ) !== false ) {
    $github_uri = parse_github_url( $plugin_data['PluginURI'] );
}
```

**Method 2: Manual Entry**

User pastes the GitHub URL or `owner/repo` format directly.

**Method 3: GitHub Search (Future)**

Search for the plugin name on GitHub:
```
GET https://api.github.com/search/repositories?q=my-plugin+language:php
```

#### 3.0.6 Plugin-to-Repository Mapping Data Model

```php
// Stored in wp_options: 'ghwp_plugin_connections'
[
    'my-plugin/my-plugin.php' => [
        'github_owner'    => 'mycompany',
        'github_repo'     => 'my-plugin',
        'github_url'      => 'https://github.com/mycompany/my-plugin',
        'auth_type'       => 'pat',  // 'none', 'pat', 'github_app'
        'auth_token'      => 'encrypted:xxxxx',
        'release_channel' => 'stable',  // 'stable', 'prerelease', 'branch:main'
        'auto_update'     => true,
        'connected_at'    => '2026-01-28 12:00:00',
        'connected_by'    => 1,  // user ID
        'last_check'      => '2026-01-28 14:00:00',
        'last_version'    => '1.3.0',
    ],
    'another-plugin/another.php' => [
        // ... another connection
    ]
]
```

#### 3.0.7 How Update Detection Works

The manager plugin **intercepts WordPress's update system**:

```php
// Hook into WordPress update check
add_filter( 'site_transient_update_plugins', 'ghwp_inject_updates' );

function ghwp_inject_updates( $transient ) {
    $connections = get_option( 'ghwp_plugin_connections', [] );

    foreach ( $connections as $plugin_file => $config ) {
        // Get installed version
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
        $installed_version = $plugin_data['Version'];

        // Check GitHub for this plugin
        $github_release = ghwp_check_github_release( $config );

        if ( $github_release && version_compare( $github_release['version'], $installed_version, '>' ) ) {
            // Inject update into WordPress's update list
            $transient->response[ $plugin_file ] = (object) [
                'slug'        => dirname( $plugin_file ),
                'plugin'      => $plugin_file,
                'new_version' => $github_release['version'],
                'package'     => $github_release['download_url'],
                'url'         => $config['github_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => '6.4',
                'requires'    => '5.0',
                'requires_php'=> '7.4',
            ];
        }
    }

    return $transient;
}
```

#### 3.0.8 Connection Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     PLUGIN ↔ GITHUB CONNECTION FLOW                          │
└─────────────────────────────────────────────────────────────────────────────┘

┌──────────────┐                                          ┌──────────────────┐
│  WordPress   │                                          │     GitHub       │
│    Admin     │                                          │   Repository     │
└──────┬───────┘                                          └────────┬─────────┘
       │                                                           │
       │  1. Admin selects plugin from dropdown                    │
       │     (lists all installed plugins)                         │
       │                                                           │
       │  2. Admin enters GitHub repo URL                          │
       │     OR clicks "Auto-detect from header"                   │
       │                                                           │
       │  3. [Test Connection] ────────────────────────────────────┤
       │                       GET /repos/:owner/:repo             │
       │                       GET /repos/:owner/:repo/releases    │
       │     ◄─────────────────────────────────────────────────────┤
       │     Returns: repo info, latest release, version           │
       │                                                           │
       │  4. Admin saves connection                                │
       │     → Stored in wp_options                                │
       │                                                           │
       │  ═══════════════════════════════════════════════════════  │
       │                    UPDATE CHECK FLOW                       │
       │  ═══════════════════════════════════════════════════════  │
       │                                                           │
       │  5. WP Cron triggers update check                         │
       │     OR Admin visits Dashboard > Updates                   │
       │     OR CI/CD calls REST endpoint                          │
       │                                                           │
       │  6. Manager plugin intercepts ────────────────────────────┤
       │     site_transient_update_plugins                         │
       │                       GET /repos/:owner/:repo/releases    │
       │     ◄─────────────────────────────────────────────────────┤
       │     Returns: latest release with download URL             │
       │                                                           │
       │  7. If new version available:                             │
       │     → Inject into WP update transient                     │
       │     → Shows in Dashboard > Updates                        │
       │                                                           │
       │  8. User clicks "Update" OR auto-update triggers          │
       │     ──────────────────────────────────────────────────────┤
       │                       Download release ZIP                │
       │     ◄─────────────────────────────────────────────────────┤
       │                                                           │
       │  9. WordPress Upgrader installs                           │
       │     → Extract, replace files, activate                    │
       │                                                           │
       └───────────────────────────────────────────────────────────┘
```

#### 3.0.9 CI/CD Integration: Targeting Specific Plugins

When CI/CD triggers an update, it specifies WHICH plugin was updated:

**REST Endpoint Design:**
```
POST /wp-json/ghwp/v1/refresh

Headers:
  X-Update-Token: <token>
  Content-Type: application/json

Body (Option A - by plugin file):
{
  "plugin": "my-plugin/my-plugin.php",
  "auto_update": true
}

Body (Option B - by GitHub repo):
{
  "repo": "mycompany/my-plugin",
  "auto_update": true,
  "version": "1.3.0"
}

Body (Option C - refresh all):
{
  "auto_update": false
}
```

**GitHub Workflow Integration:**
```yaml
# In your plugin's repo: .github/workflows/release.yml

- name: Trigger WordPress update
  run: |
    curl -X POST \
      -H "X-Update-Token: ${{ secrets.WP_UPDATE_TOKEN }}" \
      -H "Content-Type: application/json" \
      -d '{
        "repo": "${{ github.repository }}",
        "auto_update": true,
        "version": "${{ steps.version.outputs.version }}"
      }' \
      "${{ secrets.WP_SITE_URL }}/wp-json/ghwp/v1/refresh"
```

The manager plugin matches the `repo` parameter to find the corresponding WordPress plugin in its connections table.

---

### 3.1 Core Features (MVP)

#### 3.1.1 Repository Connection

**Admin Screen: Settings > GitHub Updater**

```
┌─────────────────────────────────────────────────────────────────┐
│  GitHub Repository Connection                                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Repository URL: [https://github.com/user/repo____________]    │
│                                                                 │
│  Authentication:                                                │
│  ○ Public repository (no auth required)                        │
│  ● Private repository                                           │
│      Access Token: [ghp_xxxxxxxxxxxxx___________________]      │
│      [Test Connection]                                          │
│                                                                 │
│  Status: ● Connected | Last check: 2026-01-28 12:00:00         │
│                                                                 │
│  [Disconnect] [Save Changes]                                    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Data Model:**
```php
// wp_options table
[
    'ghwp_connected_repos' => [
        'plugin-slug' => [
            'repo_url'    => 'https://github.com/user/repo',
            'repo_owner'  => 'user',
            'repo_name'   => 'repo',
            'auth_type'   => 'none|pat|github_app',
            'auth_token'  => 'encrypted_token',  // Only if private
            'connected_at'=> '2026-01-28 12:00:00',
            'last_check'  => '2026-01-28 12:00:00',
            'status'      => 'connected|error',
        ]
    ]
]
```

#### 3.1.2 Release Channel Selection

```
┌─────────────────────────────────────────────────────────────────┐
│  Release Channel                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ● Latest Stable Release (recommended)                          │
│    Only official releases marked as "Latest"                    │
│                                                                 │
│  ○ Include Pre-releases                                         │
│    Also receive alpha, beta, and RC versions                    │
│                                                                 │
│  ○ Branch Build                                                 │
│    Track a specific branch: [main_____________] ▾              │
│    ⚠ Warning: Branch builds may be unstable                     │
│                                                                 │
│  Current: v0.9.2 | Available: v0.9.3                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 3.1.3 Update Triggers

```
┌─────────────────────────────────────────────────────────────────┐
│  Update Behavior                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Check Frequency:                                               │
│  [12] hours (default: 12)                                       │
│                                                                 │
│  Auto-Update:                                                   │
│  ☐ Automatically install minor updates (0.9.x)                  │
│  ☐ Automatically install major updates (x.0.0)                  │
│                                                                 │
│  Remote Trigger:                                                │
│  ☑ Enable REST API endpoint for CI/CD triggers                 │
│                                                                 │
│  Endpoint: /wp-json/ghwp/v1/refresh                            │
│  Token: [●●●●●●●●●●●●●●●●] [Regenerate] [Copy]                │
│                                                                 │
│  [Check Now]                                                    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 3.1.4 Audit Log

```
┌─────────────────────────────────────────────────────────────────┐
│  Update History                                          [Export]│
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Date/Time          | From    | To      | Trigger    | User    │
│  ────────────────────────────────────────────────────────────── │
│  2026-01-28 12:00   | v0.9.2  | v0.9.3  | CI/CD      | system  │
│  2026-01-27 09:30   | v0.9.1  | v0.9.2  | Manual     | admin   │
│  2026-01-25 14:15   | v0.9.0  | v0.9.1  | Auto       | cron    │
│  2026-01-20 10:00   | v0.8.19 | v0.9.0  | Manual     | admin   │
│                                                                 │
│  [View Details] [Rollback to v0.9.2]                           │
│                                                                 │
│  Showing 4 of 24 entries | Page 1 of 6 | [<] [1] [2] [3] [>]   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Audit Log Schema:**
```sql
CREATE TABLE {prefix}ghwp_update_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plugin_slug VARCHAR(255) NOT NULL,
    from_version VARCHAR(50),
    to_version VARCHAR(50) NOT NULL,
    trigger_type ENUM('auto','manual','cicd','rollback') NOT NULL,
    trigger_user_id BIGINT UNSIGNED,
    trigger_ip VARCHAR(45),
    github_release_url VARCHAR(500),
    status ENUM('success','failed','rolled_back') NOT NULL,
    error_message TEXT,
    created_at DATETIME NOT NULL,
    INDEX idx_plugin_slug (plugin_slug),
    INDEX idx_created_at (created_at)
);
```

#### 3.1.5 Safe Rollback Strategy

**Approach:** Backup plugin folder before update, store in `wp-content/ghwp-backups/`

```php
// Backup structure
wp-content/
└── ghwp-backups/
    └── plugin-slug/
        ├── v0.9.2_2026-01-27_093000/
        │   └── [full plugin contents]
        ├── v0.9.1_2026-01-25_141500/
        │   └── [full plugin contents]
        └── manifest.json
```

**Manifest Format:**
```json
{
  "plugin_slug": "plugin-slug",
  "backups": [
    {
      "version": "0.9.2",
      "path": "v0.9.2_2026-01-27_093000",
      "created_at": "2026-01-27T09:30:00Z",
      "size_bytes": 1234567,
      "files_count": 96
    }
  ],
  "retention_policy": {
    "max_backups": 5,
    "max_age_days": 30
  }
}
```

**Rollback Process:**
1. Deactivate plugin
2. Move current plugin to trash folder
3. Copy backup to plugins directory
4. Reactivate plugin
5. Log rollback in audit log
6. Clear all caches

---

### 3.2 Security Requirements

#### 3.2.1 Authentication Method Comparison

| Method | Pros | Cons | Recommendation |
|--------|------|------|----------------|
| **No Auth** | Simple, no secrets | Public repos only | MVP for public repos |
| **Personal Access Token (PAT)** | Easy setup, works with private repos | Token has broad scope, expires | MVP for private repos |
| **GitHub App** | Fine-grained permissions, no expiry | Complex setup, requires app installation | Phase 2 |

**MVP Recommendation:** Support both no-auth (public) and PAT (private).

#### 3.2.2 Minimum Token Scopes

**For PAT (Personal Access Token):**
- `repo` (for private repos) OR
- `public_repo` (for public repos)
- `read:packages` (if using GitHub Packages)

**For GitHub App:**
- Repository permissions:
  - Contents: Read-only
  - Metadata: Read-only

#### 3.2.3 Token Storage

```php
// Encryption approach
class GHWP_Token_Storage {

    private const ENCRYPTION_METHOD = 'aes-256-cbc';

    public static function encrypt( $token ) {
        $key = self::get_encryption_key();
        $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::ENCRYPTION_METHOD ) );
        $encrypted = openssl_encrypt( $token, self::ENCRYPTION_METHOD, $key, 0, $iv );
        return base64_encode( $iv . $encrypted );
    }

    public static function decrypt( $encrypted_token ) {
        $key = self::get_encryption_key();
        $data = base64_decode( $encrypted_token );
        $iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
        $iv = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );
        return openssl_decrypt( $encrypted, self::ENCRYPTION_METHOD, $key, 0, $iv );
    }

    private static function get_encryption_key() {
        // Use WordPress salts as key derivation
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    }
}
```

**Storage Location:** `wp_options` table with encrypted values.

#### 3.2.4 Supply-Chain Attack Mitigations

| Risk | Mitigation | Priority |
|------|------------|----------|
| Compromised GitHub account | Owner allowlist | Phase 2 |
| Malicious release | SHA256 checksum verification | Phase 2 |
| Man-in-the-middle | HTTPS only, certificate pinning | Phase 2 |
| Token leakage | Encrypted storage, audit logging | MVP |
| Unauthorized updates | Token-based auth, capability check | MVP |

**Checksum Verification (Phase 2):**
```json
// In GitHub release body or separate .sha256 asset
{
  "files": {
    "plugin-slug.zip": "sha256:abc123...",
    "plugin-slug.zip.sig": "gpg signature (optional)"
  }
}
```

---

### 3.3 Packaging/Update Strategy

#### 3.3.1 GitHub Releases ZIP

**Preferred Approach:** Use GitHub Releases ZIP as plugin package.

**ZIP Structure Requirements:**
```
plugin-slug.zip
└── plugin-slug/
    ├── plugin-slug.php
    ├── includes/
    ├── assets/
    └── readme.txt
```

**Build Workflow Template:**
```yaml
name: Build Release
on:
  push:
    tags: ['v*']

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Build ZIP
        run: |
          mkdir -p build/${{ github.event.repository.name }}
          rsync -av --exclude='.git' --exclude='.github' ./ build/${{ github.event.repository.name }}/
          cd build && zip -r ../${{ github.event.repository.name }}.zip .

      - name: Create Release
        uses: softprops/action-gh-release@v1
        with:
          files: ${{ github.event.repository.name }}.zip
```

#### 3.3.2 WordPress Update Integration

**Transient Injection (site_transient_update_plugins filter):**

```php
add_filter( 'site_transient_update_plugins', function( $transient ) {
    // Get update info from GitHub
    $update_info = ghwp_check_for_update( 'plugin-slug' );

    if ( $update_info && version_compare( $update_info->version, $current_version, '>' ) ) {
        $transient->response['plugin-slug/plugin-slug.php'] = (object) [
            'slug'        => 'plugin-slug',
            'plugin'      => 'plugin-slug/plugin-slug.php',
            'new_version' => $update_info->version,
            'url'         => $update_info->homepage,
            'package'     => $update_info->download_url,
            'icons'       => [],
            'banners'     => [],
            'tested'      => '6.4',
            'requires'    => '5.0',
            'requires_php'=> '7.4',
        ];
    }

    return $transient;
});
```

**plugins_api Filter (for plugin details modal):**

```php
add_filter( 'plugins_api', function( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || $args->slug !== 'plugin-slug' ) {
        return $result;
    }

    return (object) [
        'name'          => 'Plugin Name',
        'slug'          => 'plugin-slug',
        'version'       => '1.0.0',
        'author'        => 'Author Name',
        'homepage'      => 'https://github.com/user/repo',
        'download_link' => 'https://github.com/user/repo/releases/download/v1.0.0/plugin-slug.zip',
        'sections'      => [
            'description' => 'Plugin description',
            'changelog'   => 'Changelog from GitHub release notes',
        ],
    ];
}, 10, 3 );
```

#### 3.3.3 Compatibility with Caching/CDNs

**Challenge:** GitHub API rate limits and CDN caching can cause stale data.

**Solutions:**
1. **Cache GitHub API responses** in transients (5-minute TTL)
2. **Use conditional requests** (If-None-Match header)
3. **Download URL bypass:** Use direct release asset URL (no API call)
4. **Webhook trigger:** GitHub can POST to refresh endpoint on release

---

### 3.4 Verification Strategy (Phase 3)

#### 3.4.1 Post-Update HTTP Checks

```php
class GHWP_Post_Update_Verifier {

    private $checks = [];

    public function add_check( $name, $url, $expected_status = 200, $expected_content = null ) {
        $this->checks[] = [
            'name'             => $name,
            'url'              => $url,
            'expected_status'  => $expected_status,
            'expected_content' => $expected_content,  // Regex pattern
        ];
    }

    public function run_checks() {
        $results = [];

        foreach ( $this->checks as $check ) {
            $response = wp_remote_get( $check['url'], ['timeout' => 30] );

            $result = [
                'name'   => $check['name'],
                'url'    => $check['url'],
                'status' => 'pass',
                'details'=> [],
            ];

            if ( is_wp_error( $response ) ) {
                $result['status'] = 'fail';
                $result['details']['error'] = $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code !== $check['expected_status'] ) {
                    $result['status'] = 'fail';
                    $result['details']['expected_status'] = $check['expected_status'];
                    $result['details']['actual_status'] = $code;
                }

                if ( $check['expected_content'] && !preg_match( $check['expected_content'], $body ) ) {
                    $result['status'] = 'fail';
                    $result['details']['content_match'] = false;
                }
            }

            $results[] = $result;
        }

        return $results;
    }
}
```

**Admin UI Configuration:**
```
┌─────────────────────────────────────────────────────────────────┐
│  Post-Update Verification Checks                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ☑ Enable verification after updates                            │
│                                                                 │
│  Checks:                                                        │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ [Homepage]  [https://example.com/] [200] [Delete]          │ │
│  │ [REST API]  [/wp-json/] [200] [Delete]                     │ │
│  │ [Plugin Page] [/my-plugin/] [200] [Delete]                 │ │
│  └────────────────────────────────────────────────────────────┘ │
│  [+ Add Check]                                                  │
│                                                                 │
│  On Failure:                                                    │
│  ● Log error and send notification                              │
│  ○ Automatically rollback                                       │
│                                                                 │
│  Notify: [admin@example.com_______________________]            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 3.4.2 GitHub Check Run Integration (Phase 3)

```yaml
# In CI workflow after triggering update
- name: Report verification status to GitHub
  run: |
    # Get verification results from WordPress
    RESULT=$(curl -s "$WP_SITE_URL/wp-json/ghwp/v1/verify-status")

    # Create GitHub Check Run
    gh api repos/$GITHUB_REPOSITORY/check-runs \
      -f name="WordPress Deployment" \
      -f head_sha="$GITHUB_SHA" \
      -f status="completed" \
      -f conclusion="$(echo $RESULT | jq -r '.all_passed ? "success" : "failure'")"
```

---

## 4. IMPLEMENTATION SCHEDULE

### Phase 0: Proof of Concept (1-2 weeks)

**Goal:** Demonstrate the core loop works with a minimal implementation.

**Outputs:**
| Item | Description |
|------|-------------|
| `github-wp-updater.php` | Single-file plugin with hardcoded config |
| Basic admin page | Simple connection status display |
| REST endpoint | `/wp-json/ghwp/v1/refresh` |

**Acceptance Criteria:**
- [ ] Plugin can check GitHub for updates
- [ ] Plugin can be updated via Dashboard > Updates
- [ ] REST endpoint triggers immediate update check
- [ ] Works on WordPress 6.0+ with PHP 7.4+

**Biggest Risks:**
| Risk | Mitigation |
|------|------------|
| GitHub API rate limits | Cache responses, use conditional requests |
| WordPress upgrader changes | Test on multiple WP versions |

---

### Phase 1: MVP Plugin + GitHub Workflow Templates (3-4 weeks)

**Goal:** Production-ready plugin with admin UI and workflow templates.

**Outputs:**
| Item | Description |
|------|-------------|
| Plugin files | Proper file structure with classes |
| Admin settings page | Full UI for connection, channel, triggers |
| Audit log | Database table and display |
| Workflow templates | YAML files users can copy to their repos |
| Documentation | README, setup guide |

**File Structure:**
```
github-wp-updater/
├── github-wp-updater.php           # Main plugin file
├── includes/
│   ├── class-ghwp-admin.php        # Admin UI
│   ├── class-ghwp-api.php          # GitHub API client
│   ├── class-ghwp-updater.php      # Update logic
│   ├── class-ghwp-rest.php         # REST endpoints
│   ├── class-ghwp-audit.php        # Audit logging
│   └── class-ghwp-token.php        # Token encryption
├── templates/
│   └── admin-settings.php          # Settings page template
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── workflows/
│   ├── auto-release.yml            # Template workflow
│   └── README.md                   # Workflow setup guide
└── readme.txt                      # WordPress.org readme
```

**Acceptance Criteria:**
- [ ] User can connect GitHub repo via admin UI
- [ ] Updates appear in Dashboard > Updates
- [ ] Manual "Check Now" works
- [ ] REST endpoint with token auth works
- [ ] Audit log records all updates
- [ ] Works with public repos (no auth)
- [ ] Works with private repos (PAT auth)

**Biggest Risks:**
| Risk | Mitigation |
|------|------------|
| Token storage security | Use WordPress salts for encryption |
| UI complexity | Follow WordPress admin UI patterns |
| Workflow setup confusion | Detailed documentation, copy-paste snippets |

---

### Phase 2: Security Hardening (2-3 weeks)

**Goal:** Enterprise-grade security features.

**Outputs:**
| Item | Description |
|------|-------------|
| GitHub App support | Fine-grained permissions |
| Checksum verification | SHA256 validation of downloads |
| Owner allowlist | Restrict updates to trusted sources |
| Tag verification | Ensure tags are signed |
| Backup/rollback | Safe version management |

**Acceptance Criteria:**
- [ ] GitHub App authentication works
- [ ] Checksum verification fails on mismatch
- [ ] Owner allowlist blocks unauthorized repos
- [ ] Rollback restores previous version
- [ ] All security features configurable via UI

**Biggest Risks:**
| Risk | Mitigation |
|------|------------|
| GitHub App setup complexity | Provide setup wizard |
| Checksum format variations | Support multiple formats |
| Rollback filesystem issues | Test on various hosting environments |

---

### Phase 3: Verification Layer (2-3 weeks)

**Goal:** Post-update validation and reporting.

**Outputs:**
| Item | Description |
|------|-------------|
| HTTP check system | Configurable URL checks |
| GitHub Check Run integration | Status reporting to GitHub |
| Email notifications | Alert on failures |
| Auto-rollback option | Revert on verification failure |

**Acceptance Criteria:**
- [ ] HTTP checks run after every update
- [ ] Failed checks trigger notifications
- [ ] GitHub shows deployment status
- [ ] Auto-rollback works when enabled
- [ ] Check results visible in admin

**Biggest Risks:**
| Risk | Mitigation |
|------|------------|
| False positives in checks | Configurable thresholds, retry logic |
| GitHub Check Run permissions | Clear documentation on required scopes |

---

### Phase 4: Multi-Site / Enterprise (3-4 weeks)

**Goal:** Support for WordPress multisite and enterprise deployments.

**Outputs:**
| Item | Description |
|------|-------------|
| Multisite support | Network-level settings |
| Centralized management | Manage multiple sites from one dashboard |
| API for external tools | Programmatic control |
| Advanced logging | Detailed debug mode |
| Webhooks | Outgoing notifications |

**Acceptance Criteria:**
- [ ] Works on WordPress multisite (network activated)
- [ ] Network admin can manage all sites
- [ ] API allows external automation
- [ ] Webhook notifications work
- [ ] Performance acceptable with 100+ plugins

**Biggest Risks:**
| Risk | Mitigation |
|------|------------|
| Multisite complexity | Extensive testing on various configurations |
| Performance at scale | Optimize database queries, caching |

---

### Summary Timeline

```
Week 1-2:   Phase 0 - Proof of Concept
Week 3-6:   Phase 1 - MVP Plugin
Week 7-9:   Phase 2 - Security Hardening
Week 10-12: Phase 3 - Verification Layer
Week 13-16: Phase 4 - Multi-Site / Enterprise
```

**Total Estimated Duration:** 12-16 weeks

---

## APPENDIX A: GitHub Workflow Template (MVP)

```yaml
# .github/workflows/wp-release.yml
# Copy this to your plugin repository

name: WordPress Plugin Release

on:
  push:
    branches: [main]
    paths:
      - 'your-plugin.php'  # Change to your main plugin file

jobs:
  release:
    runs-on: ubuntu-latest
    permissions:
      contents: write

    steps:
      - uses: actions/checkout@v4

      - name: Extract version from plugin header
        id: version
        run: |
          VERSION=$(grep -oP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+" your-plugin.php)
          echo "version=$VERSION" >> $GITHUB_OUTPUT

      - name: Check if release exists
        id: check
        run: |
          if gh release view "v${{ steps.version.outputs.version }}" &>/dev/null; then
            echo "exists=true" >> $GITHUB_OUTPUT
          else
            echo "exists=false" >> $GITHUB_OUTPUT
          fi
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}

      - name: Build plugin ZIP
        if: steps.check.outputs.exists == 'false'
        run: |
          PLUGIN_SLUG="${{ github.event.repository.name }}"
          mkdir -p build/$PLUGIN_SLUG
          rsync -av --exclude='.git' --exclude='.github' --exclude='build' ./ build/$PLUGIN_SLUG/
          cd build && zip -r ../$PLUGIN_SLUG.zip $PLUGIN_SLUG

      - name: Create GitHub Release
        if: steps.check.outputs.exists == 'false'
        run: |
          gh release create "v${{ steps.version.outputs.version }}" \
            --generate-notes \
            --title "v${{ steps.version.outputs.version }}" \
            ${{ github.event.repository.name }}.zip
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}

      - name: Trigger WordPress update
        if: steps.check.outputs.exists == 'false'
        run: |
          if [ -n "${{ secrets.WP_SITE_URL }}" ] && [ -n "${{ secrets.WP_UPDATE_TOKEN }}" ]; then
            curl -s -X POST \
              -H "X-Update-Token: ${{ secrets.WP_UPDATE_TOKEN }}" \
              -H "Content-Type: application/json" \
              -d '{"auto_update": true}' \
              "${{ secrets.WP_SITE_URL }}/wp-json/ghwp/v1/refresh"
          fi
```

---

## APPENDIX B: REST API Reference (MVP)

### POST /wp-json/ghwp/v1/refresh

Triggers an immediate update check and optional auto-update.

**Authentication:**
- Header: `X-Update-Token: <token>`
- Or: Admin user with `update_plugins` capability

**Request Body:**
```json
{
  "auto_update": true,
  "plugin_slug": "optional-specific-plugin"
}
```

**Response:**
```json
{
  "status": "success",
  "plugins_checked": 3,
  "updates_available": 1,
  "updates_installed": 1,
  "results": [
    {
      "plugin": "my-plugin/my-plugin.php",
      "from_version": "1.0.0",
      "to_version": "1.1.0",
      "status": "updated"
    }
  ],
  "time": "2026-01-28T12:00:00Z"
}
```

---

**Document End**
