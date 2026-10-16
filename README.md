# Clinic Billing

A medical billing system for a small hospital or clinic (dental, general, …), designed for **one person at the front desk
doing everything**: register, record the visit, bill, take payment, print the receipt. It is **not** a full HMS. It covers exactly:
patients, visits, billing, payments, EMI / instalment plans, follow-ups, employee attendance, manual salary entries,
reports, and an audit log.

- Currency **₹** (Indian digit grouping: ₹1,23,456.50) · dates **DD-MM-YYYY** · timezone **Asia/Kolkata**
- Laravel 12 · PHP 8.2+ · MySQL 8 · Blade + Bootstrap 5 · DataTables (server-side) · Select2 · SweetAlert2 · Toastr · Chart.js
- barryvdh/laravel-dompdf (PDFs) · maatwebsite/excel (exports) · spatie/laravel-permission (roles) · Laravel Breeze (login only)

> **Why Laravel 12 and not 11?** Every Laravel 11 release now has published security advisories, and Composer refuses to
> install it. For a system that stores patient and money records I did not switch that safety check off. The code is
> ordinary Laravel and would run unchanged on 11.

---

## 1. Quick start

**Requirements:** PHP 8.2+ (with `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `xml`), Composer 2, MySQL 8.
There is **no Node / npm step**: front-end libraries load from public CDNs, and the app's own CSS/JS live in `public/`.
(A server with no internet access needs those libraries vendored locally. See *Offline use* below.)

```bash
# 1. dependencies and config
composer install
cp .env.example .env
php artisan key:generate

# 2. database  (edit DB_USERNAME / DB_PASSWORD in .env first)
mysql -u root -p -e "CREATE DATABASE clinic_billing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 3. tables + roles + admin + demo clinic
php artisan migrate --seed
php artisan storage:link          # lets the clinic logo show

# 4. run it
php artisan serve                 # http://127.0.0.1:8000
```

`composer setup` runs steps 1 and 3 and `storage:link` for you (create the database and set `DB_*` in `.env` first), and `composer demo` wipes the database and re-seeds it
(`migrate:fresh --seed`), handy for a fresh demo.

### Demo logins

`php artisan migrate --seed` loads a demo clinic (everywhere except production). Change or delete these before real use.

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@clinic.test` | `Admin@12345` |
| Receptionist | `rita@clinic.test` | `Demo@12345` |
| Doctor (Dr. Anil Rao) | `dr.rao@clinic.test` | `Demo@12345` |

**The demo clinic** contains 12 patients, 3 doctors, 12 services, 18 invoices (paid / part-paid / unpaid / cancelled, with
discounts and GST), the textbook EMI plan (**braces ₹30,000 at ₹3,000 a month**, one instalment paid, one part-paid, so
overdue money shows on the dashboard), follow-ups in every status, 5 employees with 10 days of attendance and last
month's salary entries. It is built through the real services, so every number, receipt and audit entry is genuine.

### Scheduler (important)

Two daily jobs keep statuses current. Add one cron line on the server:

```cron
* * * * * cd /path/to/clinic-billing && php artisan schedule:run >> /dev/null 2>&1
```

| Time | Command | What it does |
| --- | --- | --- |
| 00:10 | `emi:mark-overdue` | Flags unpaid EMI instalments past their due date as Overdue |
| 00:15 | `followups:mark-missed` | Marks scheduled follow-ups from before today as Missed |

Lists and the dashboard also work these out live, so nothing is wrong if a night is missed. The jobs just keep stored
statuses tidy. Run either by hand any time: `php artisan emi:mark-overdue`.

---

## 2. The front-desk workflow (read this first)

**The patient's page is the desk.** Everything for one patient happens on that one page. You never go hunting through
Bills, Receipts and Visits. (The same guide is inside the app: *How it works* in the menu.)

```
 Search  ──►  Patient page  ──►  New bill (slide-over)  ──►  back on the patient page with Print buttons
                  │                 doctor · services · discount · "paying now"
                  ├──►  Receive payment      pays old dues, oldest bill first, one combined receipt
                  └──►  More: Record visit · Set follow-up · New EMI plan
```

### A new patient walks in
1. **Search first**: type a name or phone in the top bar (press <kbd>/</kbd> from anywhere).
2. **Not found?** The search offers *“Register … as a new patient”* and carries what you typed into the form.
3. Fill **name, gender and mobile** (age is enough; date of birth is optional). Everything else is folded away.
4. **Register & start bill**: the patient page opens with the bill panel already open.
5. In the panel: choose the doctor, add services (prices fill in), add a discount if allowed, type **Paying now** (or *Pay full*),
   pick Cash / UPI / Card…, and **Save bill**. *Also record today's visit* is on by default, so the visit is saved too.
6. You are back on the patient page with **Print bill / Print receipt** buttons.

### The same patient comes back
1. **Search** by name or phone. The result shows a red *“owes ₹…”* pill if they have unpaid bills.
2. Their page leads with **Balance due**, **what has already happened today**, and a **timeline** of every visit, bill, payment and follow-up.
3. **Owes from last time?** Click **Receive payment**, enter what they hand over. It is applied to the **oldest unpaid bill first**
   and then the next; you see the split before confirming, and one combined receipt is printed.
4. **New treatment today?** Click **New bill**. It is a separate bill; old bills are untouched.
5. **Partial or pay-later?** Save the bill with a smaller amount (or 0). The rest stays as *Due* until received.

### Appointments
Book from a patient's page (**More → Book appointment**), the top-bar **+ New** menu, or **Appointments**. A new booking is **Awaiting confirmation**:
ring or WhatsApp the patient (the WhatsApp button has the message ready), then click **Confirm** and record how they confirmed.
Changing the day, time or doctor of a confirmed appointment sends it back to *Awaiting confirmation*. A doctor cannot be booked twice at the same time (the desk can
choose **Book anyway**), and that holds across branches. When the patient arrives press **Arrived**: it opens today's visit and lands on their page, where the bill is made.
A printable **slip** is available for the patient. *Needs confirmation* is the list to work through each morning.

### Branches (one hospital, several locations)
Admin adds branches under **Branches** and ticks which branches each login works in (**Users**). Staff with more than one branch get a **branch switcher** in the top bar;
admins can use every branch, and **All branches** gives a combined view (new records made there go to the person's first branch).
Each branch keeps its own patients, visits, bills, receipts, EMI plans, follow-ups, appointments, staff attendance, salary entries and audit trail, and the dashboard and reports show the
branch you are in. **Separate per branch too: patients.** A patient is registered in one branch and only that branch sees them (search, lists, bills, appointments); the same person visiting another branch is registered there as well.
**Shared by all branches:** services, doctors, departments, settings and the number series
(patient IDs, invoice and receipt numbers never repeat across branches). **Reports** have a *Branch* picker (All branches, or one branch) so you can compare; the chosen branch is printed on the report, the Excel file and the PDF. A branch's own address and phone are printed on its bills and receipts. Existing data becomes the first branch, *Main Branch*.

### Doctor's fee, prices and new services (on the bill)
- **Choosing the doctor adds their consultation fee** as the first line (from *Masters → Doctors*). You can change its price or remove it
  (for a free follow-up); it stays removed until you pick a different doctor.
- **Prices don't have to be fixed.** Every line shows the *standard price* under the price box; if you lower (or raise) it, the line is
  flagged `↓ ₹300 less`, the total shows **Below standard prices**, and the saved bill remembers the standard price so you can always see
  how much was reduced. (This is separate from the discount, which needs a reason.)
- **Service not in the list?** Type its name in the service box and choose **Add “…” as a new service** (or pick *＋ Add a new service…*):
  name, category and usual price, and it is saved and used on the bill straight away. Anyone who can bill can do this; the full list
  stays admin-only under *Masters → Services*.

### How billing and payment fit together
| Term | Meaning |
| --- | --- |
| **Bill** (printed as *Invoice*, `INV-…`) | What was done and what it costs. Never deleted; an admin *cancels* it with a reason. |
| **Paid / Balance** | A bill is Unpaid → Partially paid → Paid. Updated automatically as money is received. |
| **Receipt** (`RCP-…`) | Proof of each payment. One *Receive payment* that covers several bills prints as one combined receipt (one row per bill, so each bill's history stays exact). |
| **EMI plan** | A *payment schedule* for a big treatment (e.g. braces ₹30,000 at ₹3,000 a month). It can stand alone, or **split one existing unpaid bill** (pick it on the EMI form). A bill that is on a plan is paid through the plan, never twice. |

**One place to take money.** *Receive payment* on the patient page is a single dialog with a tab for **Bills** (applied to the oldest unpaid bill)
and one tab per open **EMI plan** (applied to the oldest unpaid instalment). The two tabs exist because the rules differ, not because they are
different screens.

---

## 3. Configuration

**Clinic logo, login picture and details: *Settings* (admin only, under *Setup* in the menu).** The *Branding* section at the top lets you
upload, replace or remove two pictures: the **logo** (menu, login page, every printed bill and receipt) and the **login page picture** (the left side of the sign-in screen).
They change at once. It is served by the app itself, so no `storage:link` is needed. Choose *Remove the current logo* to go back to the default icon.

**The menu** is a slim colour rail that opens when you move the mouse over it. Click **Pin menu open** at the bottom to keep it open (remembered on that computer).

Everything clinic-specific is under **Settings** (admin): name, logo, address, phone, e-mail, GSTIN, invoice / receipt /
patient-ID prefixes and the invoice footer. Changing a prefix affects only *new* numbers.

`.env` essentials:

| Key | Meaning |
| --- | --- |
| `DB_*` | MySQL connection |
| `APP_TIMEZONE` | `Asia/Kolkata` (all dates and "today" use this) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` | First admin created by `db:seed`. With no `ADMIN_PASSWORD`, local/testing uses `Admin@12345` and **production generates a random one and prints it once**. A known default never reaches a live system. Re-running the seeder never overwrites an existing password. |

---

## 4. Roles

**Users and Employees are the same people, two records.** A *user* is a login (with a role). An *employee* is on the staff list, which attendance and salary use.
Creating a user adds them to Employees by default (switch off for someone not on your payroll); an employee can be linked to a login; and on the Employees page
**Add them to Employees** pulls in every login that is not listed yet. Housekeeping staff can be employees with no login.

| | Admin | Receptionist | Doctor |
| --- | :-: | :-: | :-: |
| Dashboard | ✔ | ✔ | ✔ |
| Patients: view / register / edit | ✔ ✔ ✔ | ✔ ✔ ✔ | own patients only, view |
| Visits: view / record | ✔ | ✔ | own visits |
| Follow-ups: view / set / complete / reschedule | ✔ | ✔ | own patients |
| Appointments: view / book / confirm / check in | ✔ | ✔ | own appointments, view |
| Invoices: view / create | ✔ | ✔ | view (own patients) |
| Collect payments, "Collect Due" | ✔ | ✔ | ✘ |
| **Cancel invoice** (reason required) | ✔ | ✘ | ✘ |
| EMI plans: view / create / collect | ✔ | ✔ | view |
| **EMI reschedule, cancel, mark defaulted** | ✔ | ✘ | ✘ |
| Attendance: view / mark (today + 3 days back) | ✔ | ✔ | ✘ |
| **Attendance older than 3 days** | ✔ | ✘ | ✘ |
| **Employees, salary entries** | ✔ | ✘ | ✘ |
| **Masters** (departments, doctors, services) | ✔ | ✘ | ✘ |
| **Reports · Audit log · Settings · Users · Branches** | ✔ | ✘ | ✘ |
| Branches they can work in | all | those assigned | those assigned |

A doctor sees patients they have a visit with. Link a doctor's login to their profile under *Masters → Doctors*.
Buttons a user cannot use are hidden, **and** every route enforces the same rules on the server.

---

## 5. How the important things work

**Money.** Amounts are `decimal(12,2)`, never float. All arithmetic is done in integer paise, so there is no
floating-point drift. Every operation that moves money runs in a database transaction that locks the row
(`SELECT … FOR UPDATE`), so two desks can never double-collect or overpay. Payments and invoices are **never deleted**: an
invoice is cancelled with a reason, and any money already collected stays on record.

**Numbers** (`PAT-2026-00001`, `INV-2026-00001`, `RCP-2026-00001`, `EMI-2026-00001`) come from a locked per-type, per-year
counter. They are gap-free, never reused, and safe under concurrent requests. The prefix is configurable.

**Invoice maths** (`InvoiceCalculator`, mirrored exactly by the billing screen's JavaScript): line = qty × price − item
discount; GST per line; the bill-level discount (₹ or %, reason required, cannot exceed the subtotal) comes off the
subtotal and scales the GST in proportion; non-cash payments need a reference number.

**EMI plans.** Give *either* the number of months *or* the monthly amount; the other is calculated. ₹30,000 at ₹3,000 →
10 instalments. In months mode the last instalment absorbs rounding (₹10,000 / 3 → 3,333 + 3,333 + 3,334). The first
due date is the next occurrence of the due day on/after the start date (day 31 falls on the last day of short months);
max 60 months. A down payment is collected and receipted at creation. **Payments are flexible:** any amount up to the
balance is accepted, applied to the oldest unpaid instalment first with the excess carried forward. Paying the whole
balance is an early settlement. Admin can **reschedule** the remaining balance: the old schedule is kept as a snapshot,
paid instalments stay, and a part-paid one is closed at what was paid. A plan can be tied to an invoice, in which case
payments on the plan also settle the invoice, and the invoice is closed to direct payment while the plan is open.

**Follow-ups.** Scheduled → Completed / Missed / Cancelled / Rescheduled. *Complete & record visit* opens a pre-filled
visit and links it on save. *Reschedule* keeps the old record and links the new one.

**Audit log.** Every create / change / delete of invoices, payments, EMI plans, attendance and salary entries is written to
`activity_logs` with who, when, and old → new values, **in the same transaction** as the change itself. Cancel, default,
reschedule, etc. are their own events. The log is append-only; there is no edit or delete anywhere in the app.

**Reports** (admin): Daily Collection, Invoices, Outstanding Dues, EMI Plans, Follow-ups, Doctor-wise Revenue,
Attendance, Salary Payments, each with date-range filters, and each exportable to **Excel** (one sheet per table, real
numbers and dates) and **PDF**. The screen shows the first 500 rows per table; totals and exports always cover everything.

---

## 6. Project layout

```
app/
  Enums/            statuses, payment modes, roles
  Http/Controllers/ thin: validate (Form Request) → call a service → return a view
  Http/Requests/    ALL validation, with clear messages
  Models/           Eloquent models; Concerns/TracksUser (created_by/updated_by), Concerns/Auditable
  Policies/         record-level access (e.g. a doctor's own patients)
  Services/         business rules: InvoiceService, PaymentService, EmiService, EmiPaymentService,
                    EmiScheduleGenerator, FollowUpService, AttendanceService, NumberGenerator,
                    InvoiceCalculator, DashboardService, ActivityLogger,
                    PatientPaymentService (receive payment across bills), PatientTimeline
  Reports/          one small class per report + registry (see below)
  Exports/          the Excel workbook builder
  Console/Commands/ emi:mark-overdue, followups:mark-missed
database/seeders/   RolesAndPermissionsSeeder, AdminUserSeeder, DemoDataSeeder
public/{css,js}/    the app's own assets (no build step). css/app.css is the whole design system
                    (tokens → base → layout → components → screens)
resources/views/    Blade; partials/ holds shared pieces (field, select, status badges, empty state);
                    invoices/_form is the one bill form used by the full page and the patient-page slide-over
tests/              Feature/ + Unit/ (see section 6)
```

**Add a report:** create a class extending `App\Reports\AbstractReport` (return sections of columns and rows from
`run()`), then list it in `ReportRegistry`. The page, Excel and PDF exports come for free.

**Add an audited model:** `use Auditable;` on the model (and optionally override `auditLabel()` / `auditEvent()`), then
add an entry to `ActivityLog::TYPES` and the morph map in `AppServiceProvider`.

---

## 7. Tests

```bash
php artisan test                      # the whole suite (uses an in-memory SQLite database)
php artisan test --filter=EmiTest     # one class
./vendor/bin/pint                     # code style
```

**416 tests, ~3,400 assertions.** They cover, among others: invoice totals (item and bill discounts, GST, rounding); partial and
full payments; overpayment and double-payment protection; cancellation rules; EMI schedule generation (months mode, monthly
mode, due-day clamping, 60-month limit); EMI payment allocation (less / exact / more than an instalment, spanning several,
oldest first, early settlement); EMI reschedule history; follow-up transitions and the nightly job; attendance
uniqueness (also enforced by a DB unique key) and edit-window rules; every role's access; real DataTables search/sort on
every list; every report and its Excel/PDF export; the audit trail; a seeded demo clinic checked rupee-by-rupee, and the front-desk workflow (billing + visit in one step, receive-payment
allocation oldest-first, the combined receipt, the patient page for every role); branches (a bill, visit or appointment is only visible in its own branch, the switcher, access rules, route binding) and appointments (booking, clashes, confirmation, moving, check-in, doctors seeing only their own).

The interface was also driven in a real browser (Chrome): a brand-new patient registered, billed and paid from the
patient page; a returning patient's dues received as one combined receipt; the bill panel on a phone; and every page checked
for sideways scrolling at 390px wide.

The suite runs on SQLite, which hides MySQL-only differences. Before go-live, run it once against MySQL too
(point `DB_CONNECTION=mysql` and a throw-away `DB_DATABASE` at it in `phpunit.xml`).

---

## 8. Going live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`, HTTPS, and a strong `ADMIN_PASSWORD` (or note the generated one)
- [ ] `php artisan migrate --force` then `php artisan db:seed --force` (production loads **no** demo data). When upgrading an existing install, also run `php artisan db:seed --class=RolesAndPermissionsSeeder --force` once so the new appointment and branch permissions exist; the migration makes all current data the *Main Branch* and gives every current login access to it
- [ ] `php artisan storage:link`, and make `storage/` and `bootstrap/cache/` writable
- [ ] `php artisan optimize` (config, route and view cache); re-run after every deploy
- [ ] The cron line from section 1
- [ ] **Daily database backups**, with a restore you have actually tested. This database *is* the clinic's books
- [ ] Fill in *Settings* (name, address, GSTIN, prefixes) **before** the first invoice, and create real staff under *Users*
- [ ] Delete or change the demo logins (or never load them)
- [ ] Set `SESSION_SECURE_COOKIE=true` when serving over HTTPS

**Offline use.** Bootstrap, jQuery, DataTables, Select2, SweetAlert2, Toastr, Chart.js and the fonts load from CDNs
(`resources/views/partials/assets-head.blade.php` and `assets-scripts.blade.php`). A clinic with an unreliable connection should download
those files into `public/vendor/` and point those two partials at them.

---

## 9. Known limits (by design)

- Not a full HMS: no pharmacy stock, lab orders, a drag-and-drop appointments calendar, or payroll calculation (salary entries are typed in).
- Cancelling an invoice that already has payments keeps those payments on record; refunds are handled outside the app.
- *Receive payment* covers unpaid bills only. Bills that belong to an open EMI plan are paid through the plan, and a saved bill
  cannot be edited (cancel it and make a new one), which is what keeps the books trustworthy.
- GST is calculated on each line (after the item discount), with the bill-level discount scaling it proportionally.
  There is no GST return / e-invoice integration.
- WhatsApp reminders are click-to-chat links (`wa.me`) that open WhatsApp with a ready message; nothing is sent automatically.
- Reports are admin-only because they include salary and revenue figures.

## 10. Troubleshooting

| Symptom | Fix |
| --- | --- |
| `SQLSTATE … Access denied` | Check `DB_USERNAME` / `DB_PASSWORD`; make sure the database exists |
| Logo does not show | `php artisan storage:link` |
| Pages unstyled / dropdowns do not work | The server cannot reach the CDNs (see *Offline use*) |
| "Overdue" / "Missed" statuses look stale | The cron line is missing; run `php artisan schedule:run` |
| PDF shows boxes instead of ₹ | Keep the bundled DejaVu font (`font-family` in `partials/doc-style.blade.php`) |
| `db:seed` asks "Are you sure?" | Production safety prompt: add `--force` |
| Changed a role / permission, no effect | `php artisan permission:cache-reset` |
