 Tukolerewamu Farmers' SACCO: Management Information System (Prototype)

BIT 2102 Management Information Systems and Development, Semester I, November 2026.

Group members:KOMUGISHA VANESSA, GASARO DESIRE, MANZI BRUCE
REGNO . 25/1/222/D/1424, 25/1/314/D/3022, 25/1/314/D/2612
 What it is
A browser-based prototype MIS for a village SACCO. It integrates membership, savings, loans, repayments and
management reporting, with views for different user levels.

 How to run (XAMPP + phpMyAdmin)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy the `sacco-mis` folder into `C:\xampp\htdocs\`.
3. Open http://localhost/phpmyadmin, go to the **SQL** tab (or Import) and run `database/schema.sql`, then `database/seed.sql`. This creates the `sacco` database with sample data.
4. Open **http://localhost/sacco-mis/** (do not double-click index.html: PHP must run through Apache).
5. Every record you add (member, deposit, loan, repayment, approval) is now saved in MySQL. Refresh the tables in phpMyAdmin to see it.

If your MySQL root user has a password, set it in `api/config.php`.

Sign in
The app opens as a read-only page showing every screen (Dashboard, Savings, Loans, Members, Audit) with no forms or buttons for recording data. The header has "Log in as Teller / Loans officer / Manager / Member" buttons; pressing one opens the login page for that role. After logging in, the user sees only the screens and actions for their role. Prototype logins, all with password `sacco123`: `teller1`, `officer1`, `manager1`, `nakato` (member), or any Member ID such as `M002`. Use Sign out (top right) to switch user.

 Roles
| Role | Level | Can do |
|---|---|---|
| Teller | Operational | Record deposits/withdrawals, register members |
| Loans officer | Operational | Submit loan applications, record repayments (cash, MTN MoMo, Airtel Money) |
| Manager | Tactical/Strategic | Dashboard (portfolio at risk, arrears), approve or reject loans, audit trail |
| Member | External | View own savings, loans and transactions |

 Business rules
- Withdrawals cannot exceed the member's savings balance.
- A loan cannot exceed 3 times the member's savings.
- Interest is 12% a year, flat; instalment = total repayable / months.
- A loan is overdue when its next due date has passed and a balance remains.
- Every sensitive action is written to the audit trail.

 Structure
```
sacco-mis/
  index.html        page layout
  css/style.css     styling (light and dark mode)
  js/app.js         screens; calls the API
  api/api.php       PHP API: business rules and MySQL reads/writes
  api/config.php    database host, name, user, password
  database/schema.sql  reference relational schema (matches the ERD)
```

Limitations
Prototype only: sign-in uses PHP sessions with a shared demo password for member-ID logins, and there is no automated backup.
