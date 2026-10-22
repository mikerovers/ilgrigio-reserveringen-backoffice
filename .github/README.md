# GitHub Actions Deployment Setup

This directory contains the GitHub Actions workflow for deploying to Cloudways.

## Required GitHub Secrets

To use this deployment workflow, you need to configure the following secrets in your GitHub repository settings (Settings > Secrets and variables > Actions):

### Cloudways Connection Secrets
- `CLOUDWAYS_HOST` - Your Cloudways server IP address or hostname
- `CLOUDWAYS_USERNAME` - Your Cloudways SSH username (usually the application name)
- `CLOUDWAYS_SSH_KEY` - Your private SSH key for Cloudways access
- `CLOUDWAYS_PORT` - SSH port (usually 22)
- `CLOUDWAYS_APP_PATH` - Full path to your application on Cloudways server (e.g., `/home/master/applications/your-app`)

### Application Environment Secrets
- `APP_SECRET` - Symfony application secret key
- `DATABASE_URL` - Database connection URL
- `MAILER_DSN` - Email server configuration
- `MAILER_FROM_EMAIL` - From email address for notifications
- `ADMIN_EMAIL` - Administrator email address
- `ILGRIGIO_RESERVERINGEN_BASE_URL` - Public base URL used for payment return and email links
- `MOLLIE_API_KEY` - Mollie payment API key
- `PDF_TOKEN_SECRET` - Secret key for PDF download tokens
- `PDF_TOKEN_EXPIRATION_DAYS` - Token expiration in days (default: 150)
- `MAX_TICKETS_PER_ORDER` - Maximum tickets per order (default: 10)
- `TAX_RATE` - Tax rate (default: 0.21)

### Additional Secrets
- `GITHUB_TOKEN` - GitHub token with repository access (automatically provided, no setup needed)

## Setting up SSH Access to Cloudways

1. **Generate SSH Key Pair** (if you don't have one):
   ```bash
   ssh-keygen -t rsa -b 4096 -C "github-actions@yourdomain.com"
   ```

2. **Add Public Key to Cloudways**:
   - Copy your public key content (`~/.ssh/id_rsa.pub`)
   - Go to Cloudways Console > Server Management > Master Credentials
   - Add your public key to the SSH Keys section

3. **Add Private Key to GitHub Secrets**:
   - Copy your private key content (`~/.ssh/id_rsa`)
   - Add it as `CLOUDWAYS_SSH_KEY` secret in GitHub

## Environment Variables Security

🔒 **SECURE APPROACH**: This deployment workflow uses GitHub secrets to securely inject environment variables during deployment. You **DO NOT** need to store a `.env` file on your Cloudways server.

The workflow automatically creates a secure `.env` file during deployment using the encrypted GitHub secrets listed above. This approach:
- ✅ Keeps sensitive data encrypted in GitHub's secure vault
- ✅ Prevents exposure of secrets in server files
- ✅ Allows easy secret rotation without server access
- ✅ Maintains security best practices

**Important**: Never commit `.env` files with production secrets to your repository.

## Deployment Process

The workflow automatically:
1. ✅ Runs tests and code quality checks
2. ✅ Creates a deployment package
3. ✅ Creates a backup of the current deployment
4. ✅ Deploys the new version
5. ✅ Runs database migrations
6. ✅ Sets proper file permissions
7. ✅ Cleans up after successful deployment

## Manual Deployment

If automated deployment fails, a deployment archive will be uploaded to the `manual_deploy/` directory on your server for manual extraction.

## Troubleshooting

- Ensure your Cloudways application has sufficient disk space
- Verify SSH key permissions and access
- Check that all required secrets are properly configured
- Monitor the Actions tab for detailed deployment logs
