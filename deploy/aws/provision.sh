#!/bin/bash
# Installs and deploys the app on Amazon Linux 2023. Runs as root at first boot (from the
# CloudFormation user data) and again for every later deploy (through SSM Run Command).
# Callers update the checkout first: pulling inside a running bash script can change it mid-run.
set -euo pipefail

export HOME=${HOME:-/root}   # cloud-init and SSM run without HOME, which Composer requires
APP=/var/www/donate
PARAMS=/donate   # SSM Parameter Store path holding app-url, stripe-secret and stripe-webhook-secret

# --- packages (first run only) ---------------------------------------------------------
if ! command -v php-fpm >/dev/null; then
    dnf install -y nginx php8.4-cli php8.4-fpm php8.4-mbstring php8.4-pdo php8.4-xml php8.4-intl
fi

if [ ! -x /usr/local/bin/composer ]; then
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    expected=$(curl -fsS https://composer.github.io/installer.sig)
    actual=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
    [ "$expected" = "$actual" ] || { echo "Composer installer checksum mismatch" >&2; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

# --- code ------------------------------------------------------------------------------
cd "$APP"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# --- environment: secrets come from Parameter Store, read with the instance's IAM role ---
token=$(curl -fsS -X PUT http://169.254.169.254/latest/api/token -H 'X-aws-ec2-metadata-token-ttl-seconds: 60')
region=$(curl -fsS -H "X-aws-ec2-metadata-token: $token" http://169.254.169.254/latest/meta-data/placement/region)
param() {
    aws ssm get-parameter --region "$region" --name "$PARAMS/$1" --with-decryption \
        --query Parameter.Value --output text 2>/dev/null || true
}

mkdir -p /etc/donate
[ -f /etc/donate/app_key ] || echo "base64:$(openssl rand -base64 32)" > /etc/donate/app_key
chmod 600 /etc/donate/app_key

umask 027
cat > .env <<EOF
APP_NAME="Donate Demo"
APP_ENV=production
APP_KEY=$(cat /etc/donate/app_key)
APP_DEBUG=false
APP_URL=$(param app-url)

LOG_CHANNEL=single
LOG_LEVEL=info

DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# Receipts go to the log: a public demo must not email whatever address a visitor types in.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=receipts@example.com
MAIL_FROM_NAME="Donate Demo"

STRIPE_SECRET=$(param stripe-secret)
STRIPE_WEBHOOK_SECRET=$(param stripe-webhook-secret)
EOF
umask 022
chgrp apache .env

# --- database, caches, permissions -----------------------------------------------------
touch database/database.sqlite
chown -R apache:apache storage bootstrap/cache database
sudo -u apache php artisan migrate --force
sudo -u apache php artisan optimize

# --- web server: nginx -> PHP-FPM ------------------------------------------------------
cat > /etc/nginx/nginx.conf <<'EOF'
user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log notice;
pid /run/nginx.pid;
include /usr/share/nginx/modules/*.conf;

events { worker_connections 1024; }

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /var/log/nginx/access.log;
    sendfile on;
    server_tokens off;

    server {
        listen 80 default_server;
        root /var/www/donate/public;
        index index.php;
        client_max_body_size 1m;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            fastcgi_pass unix:/run/php-fpm/www.sock;
        }

        location ~ /\.(?!well-known) { deny all; }
    }
}
EOF

# --- queue worker: sends receipts --------------------------------------------------------
cat > /etc/systemd/system/donate-queue.service <<EOF
[Unit]
Description=Donate queue worker
After=network.target

[Service]
User=apache
WorkingDirectory=$APP
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable php-fpm nginx donate-queue
systemctl restart php-fpm nginx donate-queue
echo "Deployed $(git rev-parse --short HEAD)"
