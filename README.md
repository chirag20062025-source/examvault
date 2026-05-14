# ExamVault — Online Examination System

A full-stack web-based examination platform that allows teachers to create and manage exams, and students to take them online with real-time scoring.

---

## 🚀 Live Demo
**URL:** https://examvault.infinityfreeapp.com

**Test Accounts:**
| Role | Username | Password |
|------|----------|----------|
| Student | arjun | student123 |
| Teacher | admin | teacher123 |

---

## 📋 Project Overview

ExamVault is a complete online examination system built as a college project. It supports two user roles — Teacher and Student — each with their own dashboard and features.

### Key Features
- Teacher can create exams with MCQ questions
- Students can take timed exams with auto-submit
- Negative marking support
- Question shuffling
- Real-time score calculation
- Result history and analytics
- Audit logging for all actions
- Role-based access control

---

## 🛠️ Technology Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, JavaScript (ES6+) |
| Backend | PHP 8.2 |
| Database | MySQL |
| Server | Apache (XAMPP) |
| Hosting | InfinityFree |

---

## 📁 Project Structure

```
examvault/
├── index.html                  # Landing page
├── student-login.html          # Student login
├── student-register.html       # Student registration
├── teacher-login.html          # Teacher login
├── teacher-register.html       # Teacher registration
├── config.php                  # Database configuration
│
├── api/                        # PHP Backend API
│   ├── auth.php                # Login, logout, register
│   ├── exams.php               # Exam CRUD operations
│   ├── questions.php           # Question CRUD operations
│   ├── results.php             # Exam submission and results
│   └── students.php            # Student management
│
├── student/                    # Student pages
│   ├── dashboard.html          # Student dashboard
│   ├── exams.html              # Available exams list
│   ├── exam-instructions.html  # Pre-exam instructions
│   ├── exam-taking.html        # Exam interface
│   ├── exam-result.html        # Result after exam
│   ├── results.html            # All results history
│   ├── profile.html            # Student profile
│   └── settings.html           # Settings
│
└── teacher/                    # Teacher pages
    ├── dashboard.html          # Teacher dashboard
    ├── exams.html              # Manage exams
    ├── create-exam.html        # Create new exam
    ├── edit-exam.html          # Edit exam
    ├── add-question.html       # Add questions
    ├── edit-question.html      # Edit question
    ├── questions.html          # Question bank
    ├── students.html           # View students
    ├── student-detail.html     # Student details
    └── analytics.html          # Analytics
```

---

## 🗄️ Database Schema

### Tables

**users** — All teachers and students
```sql
id, username, password, full_name, email, role,
roll_number, class_sec, institution, department, is_active
```

**exams** — Exams created by teachers
```sql
id, title, subject, duration, total_marks, passing_pct,
negative_mark, shuffle_q, instructions, status, created_by
```

**questions** — MCQ questions
```sql
id, exam_id, question_text, option_a, option_b,
option_c, option_d, correct_answer, marks, difficulty
```

**exam_results** — Student exam attempts
```sql
id, user_id, exam_id, score, total_marks,
percentage, time_taken, submitted_at
```

**user_answers** — Individual answers
```sql
id, result_id, question_id, user_answer, is_correct
```

**audit_log** — System event log
```sql
log_id, action_type, table_name, record_id,
done_by, action_time, notes
```

---

## ⚙️ Stored Procedures

| Procedure | Description |
|-----------|-------------|
| `sp_SubmitExam` | Inserts exam result and all answers atomically using a transaction |
| `sp_CalculateScore` | Calculates final score with negative marking support |
| `sp_StudentReport` | Returns full exam report for a student |

---

## 🔔 Triggers

| Trigger | Event | Action |
|---------|-------|--------|
| `trg_Auto_Grade_Answer` | BEFORE INSERT on user_answers | Auto-sets is_correct |
| `trg_Prevent_Duplicate_Attempt` | BEFORE INSERT on exam_results | Blocks duplicate attempts |
| `trg_Exam_Status_Change` | AFTER UPDATE on exams | Logs status change |
| `trg_Exam_Created` | AFTER INSERT on exams | Logs new exam |
| `trg_User_Register` | AFTER INSERT on users | Logs registration |

---

## 🔄 Exam Flow

```
1. Teacher creates exam → MySQL exams table
2. Teacher adds questions → MySQL questions table
3. Student logs in → PHP session created
4. Student opens exam list → API fetches active exams
5. Student clicks Start → Instructions page loads
6. Student clicks Begin → Exam timer starts
7. Student answers questions → Stored in memory
8. Student submits → API called with all answers
9. PHP transaction → Inserts result + answers
10. Trigger auto-grades each answer
11. Stored procedure calculates final score
12. Student redirected to result page
```

---

## 🔒 Security Features

- PDO prepared statements (SQL injection prevention)
- PHP session authentication
- Role-based access control (student/teacher)
- Duplicate attempt prevention via DB constraint
- Audit logging of all key events

---

## 💻 Local Setup

### Prerequisites
- XAMPP (Apache + MySQL + PHP 8.2)

### Installation

1. Clone or download the project
2. Copy to `C:\xampp\htdocs\examvault\`
3. Start Apache and MySQL in XAMPP
4. Open `http://localhost/phpmyadmin`
5. Create database `examvaultdb`
6. Import `ev_tables.sql`, `ev_procedures.sql`, `ev_triggers.sql`
7. Open `http://localhost/examvault`

### Database Configuration
Edit `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'examvaultdb');
define('DB_USER', 'root');
define('DB_PASS', '');
```

---

## 👥 Team

**Project:** ExamVault — Online Examination System
**Course:** Database Management Systems
**Institution:** NIT

---

## 📄 License

This project is built for educational purposes.
