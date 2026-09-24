# Central Auth & API Service (The Front Desk :7002)

Welcome to the central lobby for all our apps!

Instead of building a separate login screen, password system, and verification process for every single project (Job Tracker, MiniLeads, ContentOS, etc.), this handy little server acts as our shared front desk. 

Visitors sign up here, get their verification badge, and take that badge with them to whichever app they want to visit. Easy, unified, and zero repeated work.

Runs on PHP 8.3 CLI with a local SQLite database, neatly bundled inside Docker so you don't even need PHP installed on your laptop.

---

## What Does This Front Desk Handle?

- Signups and Logins: Creates accounts, checks passwords safely with bcrypt, and hands out 1-hour session passes (Bearer tokens).
- Real Email Verification (OTP): Connects directly to Gmail to send 6-digit confirmation codes straight to the user inbox.
- Password Resets: Forgot your key? It drops a reset code into your email so you can set a new password.
- Identity Checks (/api/me): Other app backends knock on this door to ask, "Hey, who owns this token?"
- Profile Management: Lets users update their display name and username smoothly.
- Job Tracker Storage (/api/lamaran): Safely holds job application records synced across both desktop and mobile devices.
- Safety First: Uses parameterized database queries (blocks SQL injection) and temporary rate limits to stop spam requests.

---

## Getting Started (Quick and Painless)

You only need Docker installed. Here is how to turn it on:

### 1. Set up your secret config
Make a copy of the template file:
```bash
cp .env.example .env
```

Open `.env` and fill in your Gmail details:
- `SMTP_USER`: Your Gmail address.
- `SMTP_PASS`: Your 16-character Google App Password (not your personal Google account password).
- `CORS_ORIGINS`: The URLs of your frontend webapps (comma-separated).

### 2. Fire up the container
```bash
docker compose up -d --build
```
This builds and starts the service in the background on port 7002.

### 3. Check if the lights are on
Run a quick health check:
```bash
curl http://localhost:7002/api/health
```
If you get `{"ok":true,"time":"..."}`, congratulations! The front desk is open for business.

### 4. Turning it off
When you are done for the day:
```bash
docker compose down
```
Your user accounts and databases inside `./data/auth.db` stay perfectly safe on your machine.

---

## What is Inside the Box?

```text
.
├── PRD/                    # Product specification notes
├── data/                   # Where your local auth.db lives (ignored by git)
│   └── .gitkeep
├── public/                 # The front door router (index.php)
├── src/                    # Core kitchen logic
│   ├── auth.php            # Registration, login, verification, and token logic
│   ├── config.php          # Reads your .env file cleanly without libraries
│   ├── db.php              # SQLite database setup and automated table migrations
│   ├── lamaran.php         # Job application tracker endpoints
│   ├── mail.php            # Raw socket email sender talking to Gmail via STARTTLS
│   ├── profile.php         # Profile updating logic
│   ├── response.php        # JSON formatting helpers
│   └── validate.php        # Input checks and rate limiters
├── Dockerfile              # Recipe to build the lightweight PHP runtime
├── docker-compose.yml      # 1-command startup recipe
├── .env.example            # Safe template for environment settings
└── .gitignore              # Shields secrets and databases from git
```

---

## Golden House Rules

1. Guard Your App Password: Never ever commit .env or data/auth.db to GitHub. Keep your Gmail credentials safe on your local drive.
2. The Center of the Wheel: Keep this server running whenever you are testing any of the other apps (MiniLeads, ContentOS, Job Tracker). If the front desk is sleeping, they cannot verify user tokens.
3. Database Backups: If you want to back up your user database, make a quick copy of data/auth.db somewhere outside this repository.

Happy hacking!
