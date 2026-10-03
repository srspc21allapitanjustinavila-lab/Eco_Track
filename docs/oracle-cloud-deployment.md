# Oracle Cloud Deployment Guide

This guide deploys EcoTrack to an Oracle Always Free Ubuntu 24.04 VM while
keeping the 250 MB import workflow. The public GitHub repository contains
source code only. Database exports, uploaded profile images, backup archives,
and every credential stay outside Git.

## 1. Revoke the exposed mail credential

Before creating the public repository, sign in to the Google account that owns
the old EcoTrack mail address. Open Google Account > Security > 2-Step
Verification > App passwords and delete the EcoTrack app password. Do not
create a replacement until the VM is online and you are ready to enable mail.

## 2. Publish source code only

Git for Windows is required on this computer. Close and reopen PowerShell after
installation, then run the following from the project directory. Create an
empty public GitHub repository named `EcoTrack` first and replace
`YOUR_GITHUB_USER` below.

```powershell
git init
git add .
git status
git commit -m "Prepare EcoTrack for Oracle Cloud deployment"
git branch -M main
git remote add origin https://github.com/YOUR_GITHUB_USER/EcoTrack.git
git push -u origin main
```

Stop if `git status` shows `.env`, `uploads` image files, `.local`, a SQL dump,
or backup archive. Only `uploads/.htaccess` and `uploads/users/.gitkeep` should
be included from the upload directory.

## 3. Create the Always Free VM

1. Create an Oracle Cloud Free Tier account and choose Singapore as the home
   region (`ap-singapore-1`). Oracle documents the Always Free compute and
   storage allocation at <https://www.oracle.com/cloud/free/>.
2. Create an Ubuntu 24.04 instance using `VM.Standard.A1.Flex`, 2 OCPUs, 12 GB
   RAM, and a 100 GB boot volume. Keep the Always Free badge visible before
   creating the instance.
3. Assign a public IPv4 address. In the VCN security list, allow TCP 80 and 443
   from `0.0.0.0/0`. Allow TCP 22 only from your current public IP with `/32`.
   Do not add an inbound rule for TCP 3306.
4. Download the private SSH key and record the VM public IP as `SERVER_IP`.

From PowerShell, verify SSH access:

```powershell
ssh -i C:\path\to\ecotrack-ssh.key ubuntu@SERVER_IP
```

## 4. Prepare Ubuntu

Run these commands after connecting to the VM over SSH:

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y apache2 mariadb-server git certbot python3-certbot-apache \
  php libapache2-mod-php php-mysql php-zip php-mbstring php-xml php-gd php-curl
sudo a2enmod rewrite headers
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo systemctl enable --now apache2 mariadb
```

Create the application database and two local-only users. Generate long random
passwords for both placeholders and save them only in a password manager.

```bash
sudo mysql
```

```sql
CREATE DATABASE ecotrack_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ecotrack_app'@'localhost' IDENTIFIED BY 'REPLACE_APP_PASSWORD';
GRANT ALL PRIVILEGES ON ecotrack_db.* TO 'ecotrack_app'@'localhost';
CREATE USER 'ecotrack_backup'@'localhost' IDENTIFIED BY 'REPLACE_BACKUP_PASSWORD';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON ecotrack_db.* TO 'ecotrack_backup'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 5. Deploy source and production settings

On the VM, clone the public repository and install its tracked deployment
templates:

```bash
sudo git clone https://github.com/YOUR_GITHUB_USER/EcoTrack.git /var/www/ecotrack
sudo install -m 640 -o root -g www-data /var/www/ecotrack/deployment/oracle/99-ecotrack.ini /etc/php/8.3/apache2/conf.d/99-ecotrack.ini
sudo install -m 644 -o root -g root /var/www/ecotrack/deployment/oracle/ecotrack.conf /etc/apache2/sites-available/ecotrack.conf
sudo install -m 600 -o root -g root /var/www/ecotrack/deployment/oracle/ecotrack-secrets.conf.example /etc/apache2/ecotrack-secrets.conf
sudo nano /etc/apache2/ecotrack-secrets.conf
```

In `/etc/apache2/ecotrack-secrets.conf`, replace the database password
placeholder. Keep `PASSWORD_RESET_ENABLED` as `false` in `ecotrack.conf` and
leave the SMTP settings empty. The dashboard will use its built-in explanation
fallback while `OPENAI_API_KEY` remains empty.

Enable the application site and make only the upload directory writable by
Apache:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite ecotrack.conf
sudo chown -R root:root /var/www/ecotrack
sudo find /var/www/ecotrack -type d -exec chmod 755 {} \;
sudo find /var/www/ecotrack -type f -exec chmod 644 {} \;
sudo chown -R www-data:www-data /var/www/ecotrack/uploads
sudo find /var/www/ecotrack/uploads -type d -exec chmod 750 {} \;
sudo find /var/www/ecotrack/uploads -type f -exec chmod 640 {} \;
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 6. Migrate XAMPP data without publishing it

On this computer, create an ignored staging directory and export the current
database. Enter the XAMPP root password only when prompted.

```powershell
New-Item -ItemType Directory -Force .local | Out-Null
& C:\xampp\mysql\bin\mysqldump.exe --single-transaction --routines --events --triggers -u root -p ecotrack_db > .local\ecotrack_db.sql
scp -i C:\path\to\ecotrack-ssh.key .local\ecotrack_db.sql ubuntu@SERVER_IP:/tmp/
scp -i C:\path\to\ecotrack-ssh.key -r .\uploads\users ubuntu@SERVER_IP:/tmp/ecotrack-users
```

On the VM, import data and restore uploaded profile images:

```bash
mysql -u ecotrack_app -p ecotrack_db < /tmp/ecotrack_db.sql
sudo mkdir -p /var/www/ecotrack/uploads/users
sudo cp -a /tmp/ecotrack-users/. /var/www/ecotrack/uploads/users/
sudo chown -R www-data:www-data /var/www/ecotrack/uploads
sudo find /var/www/ecotrack/uploads -type d -exec chmod 750 {} \;
sudo find /var/www/ecotrack/uploads -type f -exec chmod 640 {} \;
rm -f /tmp/ecotrack_db.sql
rm -rf /tmp/ecotrack-users
```

Verify that the expected user and waste-record counts match XAMPP before
opening the public site.

For a fresh deployment without an existing database, do not use a published
default account. Import the schema, then create the first administrator through
the database using a unique username, a real email address, and a password hash
generated locally with `password_hash`.

## 7. Claim the free HTTPS address

1. Create `ecotrack-san-manuel` at <https://www.duckdns.org/>. Set its address
   to `SERVER_IP` and wait until `nslookup ecotrack-san-manuel.duckdns.org`
   returns that address.
2. On the VM, request the certificate:

```bash
sudo certbot --apache --redirect --agree-tos --no-eff-email \
  --email YOUR_EMAIL@example.com -d ecotrack-san-manuel.duckdns.org
```

3. Confirm automatic renewal:

```bash
sudo certbot renew --dry-run
```

The site must now be used at `https://ecotrack-san-manuel.duckdns.org`.

## 8. Configure backups

Create the root-only MariaDB backup credentials file and schedule the tracked
backup script. Replace the backup password placeholder before enabling cron.

```bash
sudo install -m 700 -o root -g root /var/www/ecotrack/deployment/oracle/backup-ecotrack.sh /usr/local/sbin/backup-ecotrack.sh
sudo install -m 600 -o root -g root /var/www/ecotrack/deployment/oracle/ecotrack-backup.cnf.example /root/.ecotrack-backup.cnf
sudo nano /root/.ecotrack-backup.cnf
sudo crontab -e
```

Add this cron entry:

```text
15 2 * * * /usr/local/sbin/backup-ecotrack.sh
```

Backups remain on the VM for 14 days. Download a recent archive regularly to
another protected location before deleting or rebuilding the VM.

To restore a database backup into a temporary database, create the empty
database first, then run:

```bash
gunzip -c /var/backups/ecotrack/ecotrack_YYYY-MM-DD_HH-MM-SS.sql.gz | mysql -u ecotrack_app -p ecotrack_restore
```

## 9. Final verification and later mail setup

- Confirm HTTP redirects to HTTPS and no public TCP 3306 service exists.
- Sign in with an existing admin and staff account; check dashboards, reports,
  heatmaps, existing profile images, and a representative large import.
- Upload a JPEG, PNG, or WebP under 5 MB. Verify that a PHP file renamed as an
  image is rejected and cannot be requested from `uploads/`.
- Confirm password reset is hidden on the login page and direct reset URLs show
  the unavailable page.

When a new SMTP account is ready, put its values in
`/etc/apache2/ecotrack-secrets.conf`, change `PASSWORD_RESET_ENABLED` to `true`
in `/etc/apache2/sites-available/ecotrack.conf`, then run:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Two-factor login and email-backed data-export verification also require these
SMTP settings. Test delivery with a non-administrator account before enabling
two-factor authentication for administrators.
