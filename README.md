# Laravel Launchpad 🚀

A GitHub template for new Laravel apps: Vue (Inertia), Pest, Pint and Larastan, Docker, CI, and one-command Azure provisioning with OIDC deploys.

The template provides the base application: a Laravel 13 app with the Vue starter kit, Pest for testing, Pint and Larastan for code quality, and Dependabot for dependency updates. A Docker development environment with a SQL Server database is available through Laravel Sail. A production image, a GitHub Actions workflow that tests every change and deploys merges to Azure Container Apps, and scripts that provision, sign in to, and tear down the Azure resources are included. A neutral home page and dashboard carry no branding, and one command gives a new app its own name, tagline and icon.

## Using This Template

### What You Need Installed

Day-to-day development runs in Docker, but the setup command below runs on your own machine, so it needs these tools there. It checks the versions before it changes anything and stops with a message if one is too old:

| Tool                                                                            | Version                                                                                                                                                                                         | Why                                                                                               |
| ------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| [Git](https://git-scm.com/downloads)                                            | Any recent version.                                                                                                                                                                             | Commits and pushes the branding.                                                                  |
| [Node.js and npm](https://nodejs.org/)                                          | Node 18.3 or newer. Node 22.18 or newer is better, because that is what the project's build tooling (`vite-plus`) asks for, and older versions print warnings when installing the dependencies. | Runs `npm run brand`, and installs the dependencies if there are none.                            |
| [GitHub CLI](https://cli.github.com/), signed in                                | 2.25 or newer, the version that added `gh variable`. Sign in with `gh auth login`.                                                                                                              | Starts the first deploy, sets the repository secrets and variable, and protects `main`.           |
| [Azure CLI](https://learn.microsoft.com/cli/azure/install-azure-cli), signed in | 2.79 or newer, which is what the `containerapp` extension it installs asks for. An older one gets an older extension that this has not been tried with. Sign in with `az login`.                | Creates everything in Azure.                                                                      |
| [librsvg](https://wiki.gnome.org/Projects/LibRsvg), optional                    | Any version. `brew install librsvg`, or `apt install librsvg2-bin`.                                                                                                                             | Builds `favicon.ico` and the iOS icon for a new icon. Without it they keep the template's rocket. |

The scripts run on the older bash that ships with macOS as well as on Linux. Docker is only needed for local development, not for the setup command.

### Setting Up A New App

1. On this repository's page, select Use this template, then Create a new repository. Name the repository with 2 to 32 lowercase letters, numbers and single hyphens, starting with a letter, because its name becomes the name of everything in Azure. The new repository starts with a single commit and none of this repository's history. Read the notes at the end before making it private.

2. Clone it, and sign in to GitHub and Azure:

    ```bash
    gh auth login
    ```

    ```bash
    az login
    ```

3. Run the bootstrap command from the `main` branch, with nothing uncommitted. It asks for the name, tagline and icon, a strong SQL admin password, and a token with `read:packages` if the image is private (leave that empty for a public image), then asks to go ahead:

    ```bash
    ./deploy/bootstrap.sh
    ```

    To skip the questions, give the values as options and settings, for example:

    ```bash
    SQL_ADMIN_PASSWORD='choose-a-strong-password' ./deploy/bootstrap.sh --name "Acme Tracker" --tagline "Track everything." --icon brain --yes
    ```

4. Open the address it prints, sign in with your Microsoft account, and register your account on the app's own register page, once.

The name defaults to the repository name in title case, and the icon is a [Lucide](https://lucide.dev/icons) name such as `brain` or `heart-pulse`. Running the command again is safe, whether a step failed or you want to change a value: finished steps are skipped or repeated safely, and the first image is not built again once the app exists. Run `./deploy/bootstrap.sh --help` for the options.

### What The Command Does

It runs these steps in order, using the scripts described in the sections below, which still work on their own. Use them directly if you want to do any step by hand:

1. Sets the name, tagline and icon, the Composer package name and the README title with `npm run brand`, then commits and pushes the result. It installs the dependencies first if there are none. See [Branding](#branding).
2. Runs the `deploy` workflow once so the image exists in `ghcr.io`, and waits for it to be pushed. The workflow's Azure steps fail at this point, which is expected. See [Deploy Workflow](#deploy-workflow).
3. Provisions Azure with `deploy/azure-provision.sh`. The deployed app shows the name and tagline you set. See [Provisioning Azure](#provisioning-azure).
4. Sets up Microsoft sign-in with `deploy/azure-easyauth-setup.sh`. Until then the app answers HTTP 403 to everyone. See [Signing In To The App (Easy Auth)](#signing-in-to-the-app-easy-auth).
5. Requires the `ci` status check on `main`, because a merge deploys without waiting for the tests. If `main` already has protection it is left as it is. Where GitHub does not allow it, see the notes below.
6. Sets up the deploy sign-in with `deploy/azure-oidc-setup.sh`, which also turns on [deploying after every merge](#deploy-on-merge). That is left off when `main` could not be protected, so a red pull request cannot be merged and deployed.

It also gives the app its own Composer package name (the owner and repository, in lowercase) and its own README title, and puts the app's name in the README's example commands. The rest of this README is still the template's documentation, so rewrite it for your app.

### What A New Repository Does Not Inherit

A repository made from a template gets the files, and nothing else from this repository's settings:

- **Secrets and variables:** there are none. The bootstrap command sets `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`, `AZURE_SUBSCRIPTION_ID` and `DEPLOY_ON_MERGE` (through the OIDC script). Until then a merge shows the `deploy` job as skipped, which is the intended behaviour.
- **Branch protection:** it is not copied, so the bootstrap command sets it. GitHub only offers it for private repositories on a paid plan (GitHub Pro or above). On a free account a private repository cannot require the `ci` check, so the command leaves deploying after a merge off and tells you. Either keep the repository public, upgrade and turn it on with `gh variable set DEPLOY_ON_MERGE --body true`, or keep releases manual and start the `deploy` workflow by hand.
- **Dependabot:** the update configuration is copied, and GitHub runs its first check straight away, so pull requests start arriving on day one. Dependabot security alerts are off until you turn them on in the repository's security settings.
- **Actions:** workflows run from the first push. The `tests` workflow should pass without changes.

## Architecture

The application is a Laravel monolith that renders its Vue 3 pages server-side through Inertia.js, so routing and controllers stay in Laravel while pages are Vue components built with Vite. Authentication is handled by Laravel Fortify with session-based sign-in. Data is stored in SQL Server, which runs in a container in the Sail environment and as a service in the test workflow. Azure SQL is the intended production database.

## Key Interfaces

The starter kit's default routes:

| Method | Route                | Description                                                    |
| ------ | -------------------- | -------------------------------------------------------------- |
| `GET`  | `/`                  | Welcome page.                                                  |
| `GET`  | `/login`             | Sign-in page, with `POST /login` to authenticate.              |
| `GET`  | `/register`          | Registration page, with `POST /register` to create an account. |
| `GET`  | `/forgot-password`   | Password reset request page.                                   |
| `GET`  | `/dashboard`         | Signed-in landing page.                                        |
| `GET`  | `/settings/profile`  | Profile settings.                                              |
| `GET`  | `/settings/security` | Password and two-factor settings.                              |

### Tech Stack

- **[Laravel](https://laravel.com/docs/13.x)** — Backend framework, routing and database migrations.
- **[Vue 3](https://vuejs.org/guide/introduction.html)** — Frontend components, written in TypeScript.
- **[Inertia.js](https://inertiajs.com/docs)** — Connects Laravel controllers to Vue pages without a separate API.
- **[Laravel Fortify](https://laravel.com/docs/13.x/fortify)** — Authentication backend: sign-in, registration and password reset.
- **[Tailwind CSS](https://tailwindcss.com/docs)** — Styling.
- **[Instrument Sans](https://fonts.google.com/specimen/Instrument+Sans)** — The app's font, from the `@fontsource/instrument-sans` package and served by the app itself. Licensed under the SIL Open Font License 1.1.
- **[Vite](https://vite.dev/guide/)** — Frontend build tooling.
- **[Pest](https://pestphp.com/docs)** — Test framework.
- **[Pint](https://laravel.com/docs/13.x/pint)** — PHP code style.
- **[Larastan](https://github.com/larastan/larastan)** — PHP static analysis.
- **[SQL Server](https://learn.microsoft.com/en-us/sql/sql-server/)** — Database, run in a container locally and in CI.
- **[Laravel Sail](https://laravel.com/docs/13.x/sail)** — Docker development environment.
- **[Dependabot](https://docs.github.com/en/code-security/dependabot)** — Automated dependency update pull requests.
- **[GitHub Actions](https://docs.github.com/en/actions)** — Runs the test workflow.

## Local Development

The app runs in Docker through Laravel Sail. Sail starts the app with PHP 8.4, Node and Composer, and a SQL Server 2022 container, so nothing beyond Docker needs installing.

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/).

Starting a new app from the template needs more tools on your machine. See [What You Need Installed](#what-you-need-installed).

### Setup

1. Clone the repository:

    ```bash
    git clone git@github.com:rheannemcintosh/laravel-launchpad.git
    cd laravel-launchpad
    ```

2. Copy the environment file. `DB_PASSWORD` becomes the SQL Server `sa` password, so change it from the placeholder if the machine is shared:

    ```bash
    cp .env.example .env
    ```

3. Install the PHP dependencies. The `sail` script lives in `vendor`, so if PHP and Composer aren't installed locally, use a throwaway container (with them installed, `composer install` works too):

    ```bash
    docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
    ```

4. Start the environment. The first run builds the image and takes several minutes. It starts the app and SQL Server, and creates the `laravel` and `laravel_testing` databases:

    ```bash
    ./vendor/bin/sail up -d
    ```

5. Generate the app key, run the migrations and install the frontend dependencies inside the container:

    ```bash
    ./vendor/bin/sail artisan key:generate
    ./vendor/bin/sail artisan migrate
    ./vendor/bin/sail npm install
    ```

6. Start the frontend development server:

    ```bash
    ./vendor/bin/sail npm run dev
    ```

The app is available at `http://localhost:8000`. You can register an account at `/register` and sign in to reach the dashboard.

SQL Server is available to a database client at `127.0.0.1:1433`, with the user `sa` and the `DB_PASSWORD` from `.env`. Its data is kept in the `sail-mssql` Docker volume, so it survives restarts.

Run any command in the container by prefixing it with `./vendor/bin/sail`. Stop the environment with:

```bash
./vendor/bin/sail down
```

Add `-v` to also delete the database volume.

Install the frontend dependencies either on your machine or in the container, not both. They use different native binaries, so running `npm install` in the container replaces a `node_modules` installed locally.

If another project already uses port `8000`, `5173` or `1433` (for example another local SQL Server container), Sail fails to start with a "port is already allocated" error. Set `APP_PORT`, `VITE_PORT` or `FORWARD_DB_PORT` in `.env` to free ports and restart.

### Testing Locally

The tests run against the `laravel_testing` database, so they need the environment running. The `DB_HOST` of `mssql` only resolves inside the Sail containers, so run these through Sail.

1. Run the full check, which runs Pint, Larastan and the Pest test suite:

    ```bash
    ./vendor/bin/sail composer test
    ```

2. Run the frontend format, lint and type checks:

    ```bash
    ./vendor/bin/sail npm run check
    ./vendor/bin/sail npm run types:check
    ```

3. Fix code style automatically:

    ```bash
    ./vendor/bin/sail composer lint
    ./vendor/bin/sail npm run check:fix
    ```

The scripts in `deploy/` are tested without Azure or GitHub. The tests in `tests/Feature/Deploy` run the real scripts in a scratch git repository, with stand-ins for `az`, `gh` and `curl` in `tests/Support/fakes`. The stand-ins remember what they were asked to create in a small JSON file, so a first run, a re-run and a teardown all see consistent results, and they log every call. They only know the commands and queries the scripts use. If you change a script to use another command or query, its test fails with `fake: unsupported ...` (exit code 97), which means the stand-in needs teaching the new command, and the test then pins down what the script should do with it.

These tests start many small processes, so they take about a minute of the full run.

The PHP requirement in `composer.json` is the oldest PHP version the locked packages accept, which is `^8.4.1` and not just `^8.4`. Dependabot resolves updates against the lowest PHP version that requirement allows, so a requirement lower than what the locked packages need makes every Dependabot run for the PHP packages fail and no update is ever proposed. A test fails if the requirement falls behind. If it does, raise the requirement in `composer.json` to the version it names, refresh the lock file with `composer update --lock`, and commit both.

### Production Image

The `Dockerfile` in the repo root builds the image that gets deployed, separate from the Sail development image in `docker/8.4`. A first stage installs the production PHP dependencies and builds the frontend. A second stage copies the results into a smaller runtime image with nginx, php-fpm and the SQL Server drivers. The container runs as a non-root user and serves the app on port `8080`. On start it caches the config, routes and views, links storage and runs the migrations.

1. Build the image:

    ```bash
    docker build -t laravel-launchpad .
    ```

2. Run it. The image has no `.env` and reads its settings from environment variables, so pass the app key and the database settings. This example uses the Sail SQL Server container, so the Sail environment must be running, and the network name is the folder name followed by `_sail`:

    ```bash
    docker run --rm -p 8090:8080 --network laravel-launchpad_sail \
      -e APP_KEY="$(./vendor/bin/sail artisan key:generate --show)" \
      -e APP_URL=http://localhost:8090 \
      -e DB_CONNECTION=sqlsrv -e DB_HOST=mssql -e DB_PORT=1433 \
      -e DB_DATABASE=laravel -e DB_USERNAME=sa -e DB_PASSWORD=Your_strong_password123 \
      -e DB_ENCRYPT=yes -e DB_TRUST_SERVER_CERTIFICATE=true \
      laravel-launchpad
    ```

The app is then available at `http://localhost:8090`. Stop it with Ctrl+C. Use a real `DB_PASSWORD` and set `DB_TRUST_SERVER_CERTIFICATE` to `false` for anything other than local testing.

### Environment Variables

The full set is in `.env.example`. The ones the template relies on:

| Variable                      | Description                                                                |
| ----------------------------- | -------------------------------------------------------------------------- |
| `APP_NAME`                    | Application name, shown throughout the app. Read at runtime.               |
| `APP_TAGLINE`                 | Short line about the app, shown on the home page and dashboard.            |
| `APP_ENV`                     | Environment name, for example `local` or `production`.                     |
| `APP_KEY`                     | Encryption key, generated by `php artisan key:generate`.                   |
| `APP_DEBUG`                   | Shows detailed errors when `true`. Set to `false` in production.           |
| `APP_URL`                     | Base URL of the app, `http://localhost:8000` locally.                      |
| `DB_CONNECTION`               | Database driver, `sqlsrv` for SQL Server.                                  |
| `DB_HOST`                     | Database host, `mssql` inside Sail.                                        |
| `DB_PORT`                     | Database port, `1433`.                                                     |
| `DB_DATABASE`                 | Application database name, `laravel`. The tests use `laravel_testing`.     |
| `DB_USERNAME`                 | Database user, `sa` for the Sail container.                                |
| `DB_PASSWORD`                 | Password for the Sail SQL Server `sa` account. Must be a strong password.  |
| `DB_ENCRYPT`                  | Encrypts the database connection, `yes` by default.                        |
| `DB_TRUST_SERVER_CERTIFICATE` | Trusts the container's self-signed certificate, `true` for local use only. |
| `FORWARD_DB_PORT`             | Host port SQL Server is published on, `1433` by default.                   |
| `APP_PORT`                    | Host port the Sail app is served on, `8000` by default.                    |
| `VITE_PORT`                   | Host port for the Vite development server, `5173` by default.              |
| `WWWUSER` / `WWWGROUP`        | User and group IDs the Sail container runs as, `1000` by default.          |
| `SESSION_DRIVER`              | Where sessions are stored, `database` by default.                          |
| `QUEUE_CONNECTION`            | Queue backend, `database` by default.                                      |
| `CACHE_STORE`                 | Cache backend, `database` by default.                                      |
| `MAIL_MAILER`                 | Mail transport, `log` by default so emails are written to the log.         |

### Branding

The template ships with a neutral home page and dashboard, and no Laravel branding. A new app gets its own name, tagline and icon with one command, run from the root of the app after `npm install`:

```bash
npm run brand -- --name "Acme Tracker" --tagline "Track everything." --icon brain
```

Each option is optional, but at least one is needed. It can be run again whenever you want to change them, and a run that changes nothing leaves the files as they were.

| Option      | What it changes                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--name`    | `APP_NAME` in `.env` and `.env.example`. Shown on the home page, the dashboard, the sidebar, the sign-in pages, the browser tab and emails. It is read when the app runs, so it needs no rebuild.                                                                                                                                                                                                                                                                                   |
| `--tagline` | `APP_TAGLINE` in `.env` and `.env.example`, shown on the home page and the dashboard. Also read when the app runs.                                                                                                                                                                                                                                                                                                                                                                  |
| `--package` | A Composer package name such as `acme/acme-tracker`. Renames the package in `composer.json` (only that line), brings the fingerprint in `composer.lock` in step so Composer does not report an out-of-date lock file, makes the README's title the `--name`, and puts the package's repository name in the README's example commands (inside code blocks only). The setup command passes it for you. Without it, `composer.json`, `composer.lock` and the README are never touched. |
| `--icon`    | A [Lucide](https://lucide.dev/icons) icon name such as `brain` or `heart-pulse`. It swaps the icon in `resources/js/components/AppLogoIcon.vue` and rebuilds `public/favicon.svg` from the same shapes. Rebuild the front end to see it.                                                                                                                                                                                                                                            |

Nothing is written unless every value is valid. An unknown icon name, or a name or tagline containing a double quote, backslash or dollar sign (which would break the `.env` file), stops it with a message and changes nothing. The name and tagline can also be set by hand in `.env`.

`public/favicon.ico` and `public/apple-touch-icon.png` are pictures, which need an image converter. They are rebuilt when `rsvg-convert` is installed (`brew install librsvg`, or `apt install librsvg2-bin`) and left as they were otherwise, with a note. They are only used by old browsers and the iOS home screen. `.env.example` holds placeholders (`My App` and a generic tagline) until you run the command.

The font is Instrument Sans, installed from the `@fontsource/instrument-sans` package and built into the app, so the build downloads no font and the pages ask no other website for one. To use a different font, install its Fontsource package, for example `npm install -D @fontsource/inter`, then change the family and the `fontsource(...)` call in `vite.config.ts` and the `--font-sans` value in `resources/css/app.css` to match.

### Updating Vite+

`vite-plus` builds, lints and formats the app. It carries its own copy of Vite, `vite-plus-core`, and needs exactly the same version of it, so `package.json` points `vite` at that copy: once under `devDependencies` and once under `overrides`. The versions in those three lines must always match. Dependabot can change only one of them, which fails the build, so it is told to skip `vite-plus` and `vite`, and you update them by hand when you want a new version:

1. In `package.json`, set `vite-plus` and both `npm:@voidzero-dev/vite-plus-core@` aliases to the new version.

2. Install. The flag is needed because `vite-plus` lists optional test packages that npm otherwise reports as a conflict:

    ```bash
    npm install --legacy-peer-deps
    ```

3. Check that the build and the checks still pass:

    ```bash
    npm run build && npm run check && npm run types:check
    ```

If the build stops with "Expected @voidzero-dev/vite-plus-core@X, but found @voidzero-dev/vite-plus-core@Y", one of the three lines is out of step. TypeScript is held at version 6, and Dependabot skips major TypeScript updates, because version 7 fails the front end build.

## Deployment

### Provisioning Azure

`deploy/azure-provision.sh` creates everything an app needs in Azure with one command: a resource group, an Azure SQL server with a serverless database on the free offer, a Container Apps environment, the container app (which scales to zero) and a monthly budget alert. It names everything from the repository name, matching the `deploy` workflow below.

1. Run the `deploy` workflow once from the Actions tab so the image exists in `ghcr.io`. Its Azure steps fail at this point, which is expected.

2. Log in to Azure:

    ```bash
    az login
    ```

3. Run the script from a clone of the repository. `SQL_ADMIN_PASSWORD` must be a strong password:

    ```bash
    SQL_ADMIN_PASSWORD='choose-a-strong-password' ./deploy/azure-provision.sh
    ```

Also set `GHCR_USERNAME` and `GHCR_PAT` (a token with `read:packages`) if the `ghcr.io` package is private. The script header lists every setting, such as `APP_NAME`, `LOCATION`, `BUDGET_AMOUNT` and `BUDGET_EMAIL`. The deployed app shows the `APP_NAME` and `APP_TAGLINE` in `.env.example`, which `npm run brand` writes, so run it first if you want the live app to carry your name and tagline. Without a name there (or with the template's placeholder, `My App`) it uses the repository name in title case. Set `APP_TITLE` or `APP_TAGLINE` to override either. The budget alert emails the signed-in Azure user at 80% and 100% of the budget, or `BUDGET_EMAIL` if you set it.

Re-running the script is safe: it reuses existing resources and keeps the data and secrets. The container app is created behind Easy Auth and returns HTTP 403 to everyone until sign-in is set up, which the next section covers.

### Signing In To The App (Easy Auth)

Until sign-in is set up, the app answers HTTP 403 to everyone, including you. `deploy/azure-easyauth-setup.sh` lets you in with your Microsoft account and keeps everyone else out. Run the provisioning script first, then:

```bash
./deploy/azure-easyauth-setup.sh
```

The script:

- Creates a Microsoft Entra app registration called `<repo>-easyauth`, limited to your own directory, whose redirect address is the app's `/.auth/login/aad/callback`.
- Requires user assignment on it and assigns only the account signed in to `az`. Anyone else is refused by Microsoft before the request reaches the app, so they cannot wake the app or its database.
- Connects the app to Microsoft sign-in and switches unauthenticated visitors from the 403 to a redirect to sign in. It restricts access first and redirects last, so there is never a moment when any account in the directory could get in.
- Checks that visitors really are redirected, and tells you if that is not yet confirmed. Settings can take a few minutes to apply. The check asks the way a browser does, because Easy Auth answers a client that does not look like a browser, such as plain `curl`, with a 401 instead of the redirect.

Then:

1. Open the app's address in a browser and sign in with the allowed Microsoft account.
2. Register your account on the app's own register page, once. The app keeps its own login behind Microsoft sign-in.
3. To check that nobody else gets in, open the address in a private window and sign in with a different Microsoft account. It should be refused.

The sign-in needs a client secret. Container Apps offers no non-expiring alternative, so it expires after two years. The script shows the date and warns when it is within 60 days. To replace it, run the script again with `ROTATE_SECRET=1`, which issues a new secret and removes the old one.

| Setting           | Effect                                                                          |
| ----------------- | ------------------------------------------------------------------------------- |
| `ALLOWED_USER_ID` | Object ID of the one user to let in. Defaults to the account signed in to `az`. |
| `ROTATE_SECRET=1` | Issues a new client secret and removes the old one.                             |
| `VERIFY=false`    | Skips the final check that visitors are redirected to sign in.                  |

Running the script again is safe. It only changes what has drifted. If other users or groups have been assigned in Microsoft Entra, it reports them and leaves them alone.

### Deploy Sign-In (OIDC)

The `deploy` workflow signs in to Azure with OIDC, so no password is stored anywhere. `deploy/azure-oidc-setup.sh` sets that up for an app. It limits the sign-in to the app's own resource group, so run the provisioning script first.

1. Log in to Azure and GitHub if you haven't:

    ```bash
    az login
    ```

    ```bash
    gh auth login
    ```

2. Run the script from a clone of the repository:

    ```bash
    ./deploy/azure-oidc-setup.sh
    ```

The script creates an app registration called `<repo>-deploy`, gives it the Contributor role on `rg-<repo>` only, adds a trust rule for the repository's `main` branch and sets the three repository secrets listed below. It also sets the repository variable `DEPLOY_ON_MERGE` to `true`, which turns on deploying after a merge to `main` (see below). Re-running is safe. No client secret is created. Set `TRUST_BRANCH` to trust a different branch, which leaves deploy on merge off because merges land on `main`. Set `ENABLE_DEPLOY_ON_MERGE=false` to keep releases manual. If `gh` isn't available, the script prints the three values so you can add them by hand.

If a deploy fails with the error `AADSTS700213`, Azure names the subject it received. Re-run the script with `OIDC_SUBJECT` set to exactly that value.

The scripts in `deploy/` share their naming rules in `deploy/lib.sh`, and the `deploy` workflow derives the same names from the repository name.

### Deploy Workflow

The `deploy` GitHub Actions workflow builds the production image, pushes it to `ghcr.io` and updates an Azure Container App to run it. It runs in two ways: when a change is merged to `main` (once the repository has opted in, see below), and when you start it by hand from the Actions tab by choosing `deploy` and selecting Run workflow. Until the deploy sign-in above is set up, the build and push work and the Azure login step fails.

The workflow takes every name from the repository name, so nothing needs editing per app:

- The image is `ghcr.io/<owner>/<repo>`, tagged with the commit SHA and `latest`, built for `linux/amd64`.
- The container app is named `<repo>`, in the resource group `rg-<repo>`.

The workflow needs these repository secrets. The OIDC setup script sets them for you, or you can add them by hand under Settings, Secrets and variables, Actions. They are identifiers, not passwords:

| Secret                  | Description                                                 |
| ----------------------- | ----------------------------------------------------------- |
| `AZURE_CLIENT_ID`       | Client ID of the Microsoft Entra app registration for OIDC. |
| `AZURE_TENANT_ID`       | ID of the Microsoft Entra tenant.                           |
| `AZURE_SUBSCRIPTION_ID` | ID of the Azure subscription that holds the app.            |

The `tests` workflow runs on pushes to `main` and on pull requests, against a SQL Server service container.

#### Deploy On Merge

When a change is merged to `main`, the `deploy` workflow starts by itself. Two things must be true for it to deploy:

- The change is merged to `main`. Pull requests never deploy, and never show a deploy check.
- The repository variable `DEPLOY_ON_MERGE` is `true`. The OIDC setup script sets it. A new app made from the template has no such variable until its Azure setup is done, so a merge there shows the deploy as skipped in the Actions list instead of failing.

The deploy does not wait for the `tests` workflow, which starts at the same moment on a merge. The safeguard is that the pull request's tests have already passed before you merge it, so require the `ci` check on `main` in the repository's branch settings and only merge green pull requests.

Deploys run one at a time. A newer merge waits for a deploy that is already updating the app instead of interrupting it, and if several merges queue up, only the newest waiting one runs.

To turn it off, delete the variable and releases go back to manual:

```bash
gh variable delete DEPLOY_ON_MERGE
```

### Tearing Down An App

`deploy/azure-teardown.sh` removes everything an app created in Azure, so a test environment can be thrown away as quickly as it was set up. Deleting the resource group on its own would leave the deploy identity in Microsoft Entra and the secrets in GitHub behind, so use the script:

```bash
./deploy/azure-teardown.sh
```

It removes:

- The budget alert, which sits on the resource group rather than inside it, so the script deletes it explicitly first.
- The resource group `rg-<repo>` with everything in it: the container app, the database server and database, and the Container Apps environment.
- The app registrations `<repo>-deploy` and, if it exists, `<repo>-easyauth` in Microsoft Entra.
- The `AZURE_CLIENT_ID`, `AZURE_TENANT_ID` and `AZURE_SUBSCRIPTION_ID` repository secrets, and the `DEPLOY_ON_MERGE` variable, but only for the app named after the repository, because the repository has one set of them and they belong to that app. The variable is deleted first, so no merge tries to deploy to an app that is being removed.

The script lists everything it will delete and asks you to type the app name before it deletes anything. A resource group that contains nothing the provisioning script creates for the app is refused, so an unrelated group with the same name is never touched. Deleting a group with a database in it can take ten minutes or more, and the script waits for it to finish.

These settings change how it behaves:

| Setting                | Effect                                                                                                     |
| ---------------------- | ---------------------------------------------------------------------------------------------------------- |
| `DRY_RUN=1`            | Lists what would be removed and deletes nothing.                                                           |
| `FORCE=1`              | Skips the typed confirmation, for automation. The check on the resource group still applies.               |
| `NO_WAIT=1`            | Returns without waiting for the resource group deletion to finish.                                         |
| `REMOVE_SECRETS=false` | Leaves the repository secrets and variable alone.                                                          |
| `APP_NAME=<name>`      | Removes a differently named app, such as a throwaway one. The repository secrets are left alone for these. |

Running the script again is safe. It only removes what still exists.

## Tools & Technologies

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![Vue.js](https://img.shields.io/badge/Vue.js-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white)
![Inertia.js](https://img.shields.io/badge/Inertia.js-9553E9?style=for-the-badge&logo=inertia&logoColor=white)
![TypeScript](https://img.shields.io/badge/TypeScript-3178C6?style=for-the-badge&logo=typescript&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-646CFF?style=for-the-badge&logo=vite&logoColor=white)
![Pest](https://img.shields.io/badge/Pest-F472B6?style=for-the-badge)
![SQL Server](https://img.shields.io/badge/SQL%20Server-CC2927?style=for-the-badge&logo=microsoftsqlserver&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-2496ED?style=for-the-badge&logo=docker&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-885630?style=for-the-badge&logo=composer&logoColor=white)
![NPM](https://img.shields.io/badge/NPM-CB3837?style=for-the-badge&logo=npm&logoColor=white)
![Dependabot](https://img.shields.io/badge/Dependabot-025E8C?style=for-the-badge&logo=dependabot&logoColor=white)
![GitHub Actions](https://img.shields.io/badge/GitHub%20Actions-181717?style=for-the-badge&logo=githubactions&logoColor=white)
![Git](https://img.shields.io/badge/Git-F05032?style=for-the-badge&logo=git&logoColor=white)
![GitHub](https://img.shields.io/badge/GitHub-181717?style=for-the-badge&logo=github&logoColor=white)
![Claude Code](https://img.shields.io/badge/Claude%20Code-D97757?style=for-the-badge)
