# Veya – Education Information Hub

Veya is a web-based information hub that helps **Myanmar students** research universities in **Thailand, Singapore and Malaysia** in one place. It brings together programs, admission requirements, application steps, scholarships and tuition fees, with fees shown in six currencies including **Myanmar Kyat (MMK)** so families can compare affordability clearly.

Built as a Business IT Project (BITP) for the NCC Level 5 Diploma in Computing with Business Management, Strategy First International College.

## Features

- Browse and search universities by country, with multi-country filter pills (sort)
- Detailed university profiles: programs, admission requirements, application steps, tuition fees
- 6 currencies (Myanmar Kyat, Thai baht, Singapore Dollar, Malaysian Ringgit, United States Dollar and Euro) using fixed, manually updated exchange rates
- Scholarship listings with filters and sorts
- Side-by-side university comparison with up to three universities (sticky headers, best-value highlighting, "show differences only")
- Student accounts: register, log in, reset password, save universities and scholarships, profile and dashboard
- Student reviews of universities (ratings and comments which are reviewed by an administrator before appearing publicly.)
- Contact / inquiry form
- Admin panel for managing universities, fees, users and moderating reviews
- Security basics: prepared statements, CSRF tokens, password hashing, session timeout, security headers

## Tech Stack

- **Backend:** PHP (MySQLi)
- **Database:** MySQL 5.7
- **Frontend:** HTML, CSS, JavaScript
- **Local server:** XAMPP (Apache + MySQL) on Windows
- **Fonts:** Plus Jakarta Sans, Instrument Serif (Google Fonts)

## Getting Started

### Requirements

- [XAMPP](https://www.apachefriends.org/) with Apache and MySQL
- A MySQL client such as phpMyAdmin or MySQL Workbench

### Installation

1. **Clone the repository** into XAMPP's web folder:
   ```bash
   cd C:/xampp/htdocs
   git clone https://github.com/thuthuhan-dev/veya-education-hub.git veya
   ```

2. **Create the database.** Start Apache and MySQL in the XAMPP Control Panel, then run:
   ```sql
   CREATE DATABASE veya_db CHARACTER SET utf8mb4;
   ```

3. **Import the data.** Import `veya_db.sql` into `veya_db`, using phpMyAdmin's Import tab or:
   ```bash
   mysql -u root -p veya_db < veya_db.sql
   ```

4. **Configure the connection.** Copy `db_connect.sample.php` to `db_connect.php` and edit your MySQL host, user, password and database name:
   ```php
   $DB_HOST = "localhost";
   $DB_USER = "root";
   $DB_PASS = "YOUR_MYSQL_PASSWORD";
   $DB_NAME = "veya_db";
   $DB_PORT = 3306;
   ```
   `db_connect.php` is listed in `.gitignore`, so your real password is never committed.

5. **Create a `logs` folder** in the project root (the app writes errors to `logs/error.log`).

6. **Open the site** at `http://localhost/veya/` (or `http://localhost:8080/veya/` if Apache runs on port 8080).

### Creating an admin account

The public database dump contains no user accounts.

1. Register a normal account on the site.
2. Promote it to admin in MySQL:
   ```sql
   UPDATE users SET role = 'Admin' WHERE email = 'your@email.com';
   ```
3. Log in and open `/admin/dashboard.php`.

## Project Structure

```
veya/
├── admin/              Admin panel (universities, users, reviews, dashboard)
├── assets/             Images and logos
├── includes/           Shared nav, footer and helper functions
├── index.php           Home page
├── universities.php    University search and filters
├── university_detail.php
├── scholarships.php
├── compare.php         University comparison
├── dashboard.php       Student dashboard
├── login.php / register.php / forgot_password.php / reset_password.php
├── db_connect.sample.php   Template for database settings
└── veya_db.sql         Database structure and sample data
```

## Scope and Limitations

- Covers selected institutions in Thailand, Singapore and Malaysia only.
- Currency conversion uses fixed rates that are updated manually, not live exchange rates.
- University information is indicative. Always confirm details on the official university website.
- Not connected to any university application portal.

## Future Work

- Automated university recommendations
- Real-time chat support
- Expansion to more countries
- Direct application portal integration

## Author

**Thu Thu Han** (Candidate ID: 218662)
Strategy First International College, Mandalay

## License

This project was created for academic purposes.
