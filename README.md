# CICD Demo Project (PHP + MariaDB)

A small PHP web page, backed by its own MariaDB database container,
auto-deployed to a server via GitHub Actions whenever you push to
`main`.

## 1. (Optional) Run it locally

You need Docker Desktop (or Docker Engine) installed - no PHP or
MySQL install required locally, both run as containers.

```bash
cp .env.example .env
nano .env   # set real values for everything, especially the passwords
docker compose build
docker compose up
```

Open `http://localhost` (the `web` container maps to port 80 inside
Docker - if you need it on a different local port, add `ports:
["8080:80"]` under the `web` service temporarily). You should see 3
seeded items and a form to add more.

The seed data in `db-init/init.sql` only runs the FIRST time the `db`
container starts (when `./data` doesn't exist yet). If you change
`init.sql` later and want it to re-seed, delete the `./data` folder
and restart - that wipes the database, so only do this locally.

Read `app/db.php` and `app/index.php` - they're short and commented.
All database access goes through `db.php`; `index.php` never writes
raw SQL itself. Keep that separation as you extend this.

## 2. Push this to your own GitHub repo

```Terminal (cmd)
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin git@github.com:YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

alternatively, if you use HTTPS token or web authentication instead of SSH keys to connect to GitHub do

```Terminal (cmd)
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

(Create the empty repo on GitHub first, without a README, so there's
nothing to conflict with your push.)

## 3. Setup SSH access from Github to the Hosting server

```Terminal (cmd)
ssh-keygen -t ed25519 -f cicd-actions-key -N ""
type cicd-actions-key.pub
```

Copy the entire line output and send it to Nikos so that the key can be authorized on the Hosting server

```Terminal (cmd)
type cicd-actions-key
```
Copy that output as it appears.  On GitHub go to your repo → Settings → Secrets and variables → Actions → New Repository Secret and create a key called 

SSH_PRIVATE_KEY and paste the output of the command above (the private key obtained above)

create additional Repository Secrets and set a value

SITE_USERNAME — admin (or any other value)

SITE_PASSWORD — pass (or any other value)

DB_HOST — db (or any other value)

DB_NAME — db1 (or any other value)

DB_USER — dbuser (or any other value)

DB_PASSWORD — dbpass (or any other value)

DB_ROOT_PASSWORD — dbrootpass (or any other value)


## 4. Test end-to-end CICD workflow

Visit your subdomain - expect a brief HTTPS certificate warning on
the very first load (retry after ~60 seconds).

Push a small change to `main` 
e.g. edit the HTML TITLE tag in /app/index.php
and look for the change on the website directly (refresh local browser cache if needed)
You can check the GitHub Actions tab also - it should
finish green, ending with `Deployed commit: ...`.


## How this is organized

- `app/db.php` - all database access (PDO/MySQL)
- `app/index.php` - the page itself: Basic Auth check, then reads via
  `db.php`, renders HTML
- `db-init/init.sql` - schema + seed data, run once by MariaDB on
  first container start
- `Dockerfile` - packages the PHP app into an Apache image
- `docker-compose.yml` - two services: `web` (your app, on the shared
  `traefik` network) and `db` (MariaDB, on an `internal`-only network
  - it's never reachable from the internet, only from `web`)
- `.github/workflows/deploy.yml` - on every push to `main`: pulls new
  code on the server, rebuilds, restarts

To extend this: add routes/logic in `app/index.php` (or split into
more `.php` files), add tables/queries in `app/db.php`, update
`db-init/init.sql` for new seed data (remember it only runs once per
database volume).
