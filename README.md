# ClickUp Comment De-threader

Threaded replies in ClickUp are easy to miss. They're hidden under the comment they reply to.

This tool finds those replies and posts each one as a normal comment on the task, so everyone can see them.

> **Unofficial.** This project is not affiliated with or endorsed by ClickUp.

## What it does

It runs in two steps:

1. **Back up.** It scans your workspace for comments that have replies and saves them, with their attachments, to your computer. Nothing in ClickUp changes.
2. **Unthread.** For each saved reply, it posts a new comment on the task that shows:
   - who wrote the reply and when
   - which comment it was replying to
   - the reply text
   - any attachments

**It never deletes or edits anything.** Your original threads stay where they are. It's also safe to run again, because replies it has already posted are skipped.

## What you need

- PHP 8.0 or newer, with the `pdo_sqlite` extension (most PHP installs include it)
- [Composer](https://getcomposer.org/)
- A ClickUp API token
- Your ClickUp workspace ID

## Setup

**1. Download and install**

```bash
git clone https://github.com/JonLaliberte/clickup-fix-threaded-comments.git
cd clickup-fix-threaded-comments
composer install
cp env.example .env
```

**2. Get your API token**

In ClickUp, go to **Settings → Apps → API Token** and generate a token.

**3. Find your workspace ID**

Open ClickUp in a browser and look at the address bar:

```
https://app.clickup.com/1234567/home
                        ^^^^^^^
                        this is your workspace ID
```

**4. Add both to `.env`**

```env
CLICKUP_API_KEY=pk_your_token_here
CLICKUP_TEAM_ID=1234567
```

**5. Check that it works**

```bash
php test_api.php
```

You should see a few `✓ Success!` lines.

## Usage

Start small. Try one comment first and check the result in ClickUp before doing everything.

```bash
# Back up one list (find the list ID in the list's URL)
php src/main.php --backup --list-id 901234567

# Unthread a single comment and check how it looks in ClickUp
php src/main.php --unthread --comment-id 90110000000000

# Happy with it? Unthread the rest of the list
php src/main.php --unthread --list-id 901234567
```

When you're ready for the whole workspace:

```bash
php src/main.php --backup
php src/main.php --unthread
```

### All options

| Command | What it does |
|---------|--------------|
| `--backup` | Find threaded comments and save them locally (this is the default) |
| `--unthread` | Post saved replies as normal comments |
| `--list-id <id>` | Only work on one list |
| `--task-id <id>` | Only work on one task (unthread only) |
| `--comment-id <id>` | Only work on one comment thread (unthread only) |

## Good to know

- **It's slow on purpose.** ClickUp limits how many requests you can make per minute, so the tool waits about half a second between requests. A big workspace can take a while. If you see "429" errors, raise `CLICKUP_RATE_LIMIT_DELAY` in `.env`.
- **Your data stays local.** Backups go in `data/` and attachments go in `attachments/`. Both may contain private workspace content, so don't share them. They're already excluded from git.

## Troubleshooting

| Error | Fix |
|-------|-----|
| `CLICKUP_API_KEY is required` | You haven't created `.env`. Run `cp env.example .env` and fill it in. |
| `No spaces found in workspace` | Double-check your workspace ID, and that your account can access that workspace. |
| `Failed to connect to ClickUp API` | Check your internet connection, and make sure your token hasn't been revoked. |

## License

[MIT](LICENSE)
