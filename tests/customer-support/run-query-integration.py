#!/usr/bin/env python3
"""Run real WP/Woo persistence tests in a fresh, socket-only disposable MySQL.

Uses existing local engine/source packages; never copies wp-config, uploads,
database files, other plugins or site data. No services or provider calls.
"""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time


parser = argparse.ArgumentParser()
parser.add_argument("--wordpress-source", required=True)
parser.add_argument("--woocommerce-source", required=True)
parser.add_argument("--mysql-base", required=True)
parser.add_argument("--php", required=True)
args = parser.parse_args()
wp_source = Path(args.wordpress_source).resolve(strict=True)
woo_source = Path(args.woocommerce_source).resolve(strict=True)
mysql_base = Path(args.mysql_base).resolve(strict=True)
php = str(Path(args.php).resolve(strict=True))
tests = Path(__file__).resolve().parent
environment = {"PATH": "/usr/bin:/bin:/usr/sbin:/sbin", "HOME": str(tests)}
process = None

with tempfile.TemporaryDirectory(prefix=".disposable-query-", dir=tests) as temporary, tempfile.TemporaryDirectory(prefix="ycs-", dir="/tmp") as socket_directory:
    root = Path(temporary)
    os.chmod(root, 0o700)
    os.chmod(socket_directory, 0o700)
    wp = root / "wordpress"
    wp.mkdir(mode=0o700)
    for directory in ("wp-admin", "wp-includes"):
        shutil.copytree(wp_source / directory, wp / directory)
    for source in wp_source.glob("*.php"):
        if source.name != "wp-config.php":
            shutil.copyfile(source, wp / source.name)
    shutil.copytree(woo_source, wp / "wp-content/plugins/woocommerce")
    (wp / ".support-disposable").write_text("YR-0214-disposable-wordpress")
    socket = str(Path(socket_directory) / "mysql.sock")
    datadir = root / "mysql"
    datadir.mkdir(mode=0o700)
    mysqld = str(mysql_base / "bin/mysqld")
    mysql = str(mysql_base / "bin/mysql")
    common = [mysqld, "--no-defaults", "--basedir=" + str(mysql_base), "--datadir=" + str(datadir)]
    log = root / "mysql.log"

    def run(command, **kwargs):
        result = subprocess.run(command, env=environment, capture_output=True, text=True, timeout=180, **kwargs)
        if result.returncode:
            raise RuntimeError(result.stderr[-4000:] or result.stdout[-4000:])
        return result.stdout

    try:
        run(common + ["--initialize-insecure", "--skip-networking", "--mysqlx=OFF"])
        process = subprocess.Popen(common + ["--socket=" + socket, "--pid-file=" + str(root / "mysql.pid"), "--log-error=" + str(log),
            "--skip-networking", "--mysqlx=OFF", "--skip-log-bin", "--innodb-buffer-pool-size=32M"], env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError(log.read_text()[-4000:])
            if Path(socket).exists():
                break
            time.sleep(0.1)
        else:
            raise RuntimeError("Disposable MySQL did not become ready")
        run([mysql, "--no-defaults", "--protocol=SOCKET", "--socket=" + socket, "--user=root", "-e",
            "CREATE DATABASE yoro_support_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"])
        configuration = "<?php\n" + "\n".join("define(%s, %s);" % (json.dumps(key), json.dumps(value)) for key, value in {
            "DB_NAME": "yoro_support_disposable", "DB_USER": "root", "DB_PASSWORD": "", "DB_HOST": "localhost:" + socket,
            "DB_CHARSET": "utf8mb4", "DB_COLLATE": "", "AUTH_KEY": secrets.token_hex(32), "SECURE_AUTH_KEY": secrets.token_hex(32),
            "LOGGED_IN_KEY": secrets.token_hex(32), "NONCE_KEY": secrets.token_hex(32), "WP_HTTP_BLOCK_EXTERNAL": True,
            "DISABLE_WP_CRON": True, "AUTOMATIC_UPDATER_DISABLED": True, "SAVEQUERIES": True,
            "WP_HOME": "https://support-fixture.invalid", "WP_SITEURL": "https://support-fixture.invalid",
        }.items()) + "\n$table_prefix = 'support_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__.'/'); }\nrequire ABSPATH.'wp-settings.php';\n"
        (wp / "wp-config.php").write_text(configuration)
        os.chmod(wp / "wp-config.php", 0o600)
        bootstrap = root / "install.php"
        bootstrap.write_text("<?php\ndefine('WP_INSTALLING', true);\nfunction wp_mail(...$args) { return true; }\nrequire $argv[1].'/wp-load.php';\nrequire ABSPATH.'wp-admin/includes/upgrade.php';\nwp_install('Disposable support fixture', 'synthetic-owner', 'nobody@example.invalid', false, '', 'synthetic-only-password');\nupdate_option('active_plugins', ['woocommerce/woocommerce.php']);\n")
        run([php, str(bootstrap), str(wp)])
        output = run([php, str(tests / "facts-query-integration.php"), str(wp)])
        print(output.strip())
    finally:
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=20)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
