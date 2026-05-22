# Cloudways Server Configuration

This document explains the one-time server configuration needed to resolve cache/log permission conflicts between the SSH deployment user and the PHP-FPM web server user.

## Problem

The deployment process fails with permission errors because:
- Cache/log files are created by PHP-FPM (owned by web server user, e.g., `www-data` or `master-app`)
- Deployment runs via SSH (owned by SSH user, e.g., `master`)
- Neither user can modify the other's files

## Solution: Filesystem ACLs + setgid

Configure the `var/` directory so both users can read/write/delete files regardless of who created them.

### Option 1: Via Cloudways Support (Recommended)

Contact Cloudways support and request they run these commands on your server:

```bash
cd /home/master/applications/wwtemsfcqh/public_html

# Set ownership to SSH user with web server group
sudo chown -R master:www-data var/

# Set setgid bit so new files inherit group ownership
sudo chmod -R 2775 var/

# Configure ACLs so both users have full access
sudo setfacl -R -m u:master:rwX,u:www-data:rwX var/
sudo setfacl -dR -m u:master:rwX,u:www-data:rwX var/
```

### Option 2: Via Cloudways Control Panel

1. Log into Cloudways dashboard
2. Navigate to your application
3. Check for "File Manager" or "Permission Settings"
4. Set `var/cache` and `var/log` to `775` permissions
5. Ensure group ownership is shared between deployment and web users

### Option 3: Using Application Settings

Some Cloudways setups allow setting the PHP-FPM user to match the SSH user. Check:
- Application Settings → Advanced Settings
- Look for PHP-FPM Pool Configuration
- Set user/group if available

## Verification

After configuration, verify with:

```bash
cd /home/master/applications/wwtemsfcqh/public_html
ls -la var/cache/
getfacl var/cache/
```

Expected output:
- Directories should show `drwxrwsr-x` (note the 's' for setgid)
- Both users should appear in ACL output
- Group should be shared (e.g., `www-data` or `master-app`)

## Alternative: Symfony Cache Adapter

If filesystem ACLs aren't available, configure Symfony to use a different cache backend:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.redis
        system: cache.adapter.redis
```

This moves cache out of the filesystem entirely.
