# GigLink

A trust & reputation layer for the informal economy. Youth unemployment is a serious community problem — young people have real skills (cleaning, delivery, tutoring, farm work, phone repair, hairdressing, data entry) but no reliable way to reach the people who would pay for that help. GigLink closes that gap and, unlike a plain job board, builds a **portable work identity** for every worker, one gig at a time.

## What's in this build

**Roles**
- **Youth (worker)** — register, list skills + bio + location + availability windows, browse / apply to jobs, track application status, build a public work-ID (printable QR card).
- **Employer** — register as business or household, post / edit / close jobs, see ranked applicants with reputation data, accept/reject (contact revealed only on accept), rate workers, browse workers' posted availability.

**Differentiators**
- **Portable reputation** — printable `/profile_qr.php?id=…` work-ID card with QR code
- **Community-vouched endorsements** — anyone can be endorsed by chiefs/schools/employers; shown on profile
- **Two-way ratings** — available after the application is completed
- **Reverse search** — workers post availability windows; employers browse `find_workers.php`
- **Group gigs** — jobs have `workers_needed`, applicants can be marked team-lead
- **Work history** — public count of completed gigs, eligible employer reviews, skills, and attributed endorsements
- **Payment records** — employers record completed-gig payments, and workers confirm receipt or flag a dispute

**Security**
- PDO prepared statements everywhere
- `password_hash` / `password_verify` (bcrypt)
- Role gating via `require_role('youth'|'employer')`
- CSRF tokens on every form
- All output HTML-escaped with `e()`
- FK cascades on delete

## Install

1. Start Apache and MariaDB in XAMPP. The local configuration defaults to MariaDB port 3307. Override it with `GIGLINK_DB_PORT` if necessary.
2. On a fresh database, follow the import order in [DEPLOY.md](DEPLOY.md) through migration 09. For an existing Phase 8 installation, apply only 09. See [Mobile Money sandbox deployment and validation](MOBILE_MONEY_SANDBOX.md).
3. Open [GigLink locally](http://localhost/giglink/).

## Demo accounts

All seeded accounts use password: **`password`**

| Email                     | Role     |
|---------------------------|----------|
| alice@giglink.test        | youth    |
| brian@giglink.test        | youth    |
| mama@giglink.test         | employer |
| shopowner@giglink.test    | employer |
| admin@giglink.test        | admin    |

## File layout

```
giglink/
├── index.php                 landing + problem statement + latest jobs
├── login.php, logout.php
├── register_youth.php, register_employer.php
├── jobs.php, job_view.php    public job browse + detail
├── workers.php, worker_view.php, profile_qr.php
├── includes/                 db.php, auth.php, helpers.php, header.php, footer.php
├── youth/
│   ├── dashboard.php         applications + availability
│   ├── profile.php
│   └── availability.php
├── employer/
│   ├── dashboard.php         jobs posted + metrics
│   ├── profile.php
│   ├── post_job.php, edit_job.php
│   ├── applicants.php        accept/reject + rate
│   └── find_workers.php      REVERSE SEARCH on availability
├── actions/                  POST endpoints (CSRF-protected)
├── sql/schema.sql            tables + views + procedures + triggers + seed
└── assets/style.css
```

## Database objects

**Tables:** users, locations, categories, skills, youth_profiles, employer_profiles, youth_skills, jobs, applications, availability_windows, endorsements, ratings, payments

**Views:** v_jobs_public, v_worker_summary, v_employer_summary, v_worker_availability_active

Hiring, work status, listing renewal, and payment transactions live in `includes/workflow.php` and `includes/payments.php`. The database retains the rating and endorsement procedures and checks that reviews belong to completed applications.

See [work and payment transitions, deployment, and tests](RELIABILITY.md), and [JSON API endpoints](api/README.md).

See [dispute resolution, payment reconciliation, and trust dashboards](TRUST_RECONCILIATION.md) for Phase 6.
