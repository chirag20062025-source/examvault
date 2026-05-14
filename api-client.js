// ============================================================
//  ExamVault — API Client  (replaces localStorage db object)
//  Include this file in every HTML page instead of the inline
//  localStorage database simulation.
//
//  Usage: replace  db.insert(...)  with  API.exams.create(...)
//         replace  db.select(...)  with  API.exams.list()
//         etc.
// ============================================================

async function _apiFetch(path, method='GET', body=null) {
  const opts = {
    method,
    credentials: 'include',   // ← THIS IS THE KEY LINE
    headers: { 'Content-Type': 'application/json' }
  };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(API_BASE + '/' + path, opts);
  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'Server error');
  return json.data;
}
const API_BASE = '../api';   // adjust if your folder structure differs

// ============================================================
//  Core HTTP helper
// ============================================================
async function _req(url, method = 'GET', body = null) {
  const opts = {
    method,
    credentials: 'include',   // send PHP session cookie
    headers: { 'Content-Type': 'application/json' },
  };
  if (body) opts.body = JSON.stringify(body);

  const res  = await fetch(url, opts);
  const data = await res.json();

  if (!data.success) {
    // Bubble up server error as a thrown Error
    throw new Error(data.message || 'Server error');
  }
  return data.data ?? null;
}

function get(path)          { return _req(`${API_BASE}/${path}`, 'GET'); }
function post(path, body)   { return _req(`${API_BASE}/${path}`, 'POST', body); }

// ============================================================
//  Auth
// ============================================================
const Auth = {
  /** Login — returns user object */
  login(role, username, password) {
    return post('auth.php?action=login', { role, username, password });
  },

  /** Register new student or teacher */
  register(role, fields) {
    return post('auth.php?action=register', { role, ...fields });
  },

  /** Logout */
  logout() {
    return post('auth.php?action=logout');
  },

  /** Get current logged-in user (refreshes from DB) */
  me() {
    return get('auth.php?action=me');
  },

  /** Update profile / change password */
  updateProfile(fields) {
    return post('auth.php?action=update_profile', fields);
  },
};

// ============================================================
//  Exams
// ============================================================
const Exams = {
  list() {
    return get('exams.php');
  },
  get(id) {
    return get(`exams.php?id=${id}`);
  },
  create(fields) {
    return post('exams.php?action=create', fields);
  },
  update(id, fields) {
    return post('exams.php?action=update', { id, ...fields });
  },
  delete(id) {
    return post('exams.php?action=delete', { id });
  },
  setStatus(id, status) {
    return post('exams.php?action=status', { id, status });
  },
};

// ============================================================
//  Questions
// ============================================================
const Questions = {
  list(exam_id) {
    return get(`questions.php?exam_id=${exam_id}`);
  },
  get(id) {
    return get(`questions.php?id=${id}`);
  },
  create(fields) {
    return post('questions.php?action=create', fields);
  },
  bulkCreate(exam_id, questions) {
    return post('questions.php?action=bulk_create', { exam_id, questions });
  },
  update(id, fields) {
    return post('questions.php?action=update', { id, ...fields });
  },
  delete(id) {
    return post('questions.php?action=delete', { id });
  },
};

// ============================================================
//  Results
// ============================================================
const Results = {
  /** Submit exam — answers: [{question_id, answer}] */
  submit(exam_id, answers, time_taken) {
    return post('results.php?action=submit', { exam_id, answers, time_taken });
  },
  list() {
    return get('results.php');
  },
  get(id) {
    return get(`results.php?id=${id}`);
  },
  forExam(exam_id) {
    return get(`results.php?exam_id=${exam_id}`);
  },
  forStudent(student_id) {
    return get(`results.php?student_id=${student_id}`);
  },
};

// ============================================================
//  Analytics (teacher)
// ============================================================
const Analytics = {
  dashboard() {
    return get('analytics.php?type=dashboard');
  },
  exam(id) {
    return get(`analytics.php?type=exam&id=${id}`);
  },
  students() {
    return get('analytics.php?type=students');
  },
  leaderboard(exam_id) {
    return get(`analytics.php?type=leaderboard&exam_id=${exam_id}`);
  },
};

// ============================================================
//  Students (teacher CRUD)
// ============================================================
const Students = {
  list() {
    return get('students.php');
  },
  get(id) {
    return get(`students.php?id=${id}`);
  },
  toggleActive(id, is_active) {
    return post('students.php?action=toggle_active', { id, is_active });
  },
};

// ============================================================
//  Session helpers  (replaces sessionStorage CU object)
// ============================================================
let _currentUser = null;

async function initSession(role) {
  try {
    _currentUser = await Auth.me();
    if (!_currentUser || _currentUser.role !== role) {
      window.location.href = role === 'teacher'
        ? (window.location.pathname.includes('/teacher/') ? '../teacher-login.html' : 'teacher-login.html')
        : (window.location.pathname.includes('/student/') ? '../student-login.html' : 'student-login.html');
    }
    return _currentUser;
  } catch {
    window.location.href = role === 'teacher' ? '../teacher-login.html' : '../student-login.html';
  }
}

function getCurrentUser() { return _currentUser; }

// ============================================================
//  Drop-in handlers for login / register / logout pages
// ============================================================

/**
 * Call from student-login.html / teacher-login.html
 * Replaces the old handleLogin() that read from localStorage
 */
async function handleLogin(role) {
  const u = document.getElementById('aU')?.value.trim();
  const p = document.getElementById('aP')?.value;
  if (!u || !p) { toast('Please enter username and password.', 'e'); return; }

  try {
    const user = await Auth.login(role, u, p);
    toast('Welcome, ' + user.full_name + '!', 's');
    setTimeout(() => {
      window.location.href = role === 'teacher' ? 'teacher/dashboard.html' : 'student/dashboard.html';
    }, 600);
  } catch (err) {
    toast(err.message || 'Login failed.', 'e');
  }
}

/**
 * Call from student-register.html / teacher-register.html
 */
async function handleRegister(role) {
  const fields = {
    username  : document.getElementById('aU')?.value.trim(),
    password  : document.getElementById('aP')?.value,
    full_name : document.getElementById('aN')?.value.trim(),
    email     : document.getElementById('aE')?.value.trim(),
  };
  if (role === 'teacher') {
    fields.department = document.getElementById('aD')?.value.trim();
    fields.subject    = document.getElementById('aSub')?.value.trim();
  } else {
    fields.roll_number = document.getElementById('aR')?.value.trim();
    fields.class_sec   = document.getElementById('aC')?.value.trim();
    fields.institution = document.getElementById('aI')?.value.trim();
  }

  try {
    await Auth.register(role, fields);
    toast('Account created! Please sign in.', 's');
    setTimeout(() => { window.location.href = role + '-login.html'; }, 800);
  } catch (err) {
    toast(err.message || 'Registration failed.', 'e');
  }
}

/**
 * Logout button handler
 */
async function handleLogout() {
  await Auth.logout();
  window.location.href = '../index.html';
}

// ============================================================
//  Exam-taking submit  (replaces confirmSubmit in exam-taking.html)
// ============================================================

/**
 * @param {number}  examId
 * @param {object}  answersMap  — { questionIndex: 'A'|'B'|'C'|'D' }
 * @param {Array}   questions   — question objects with .id field
 * @param {number}  timeTaken   — seconds
 */
async function submitExamToServer(examId, answersMap, questions, timeTaken) {
  const answers = questions.map((q, i) => ({
    question_id : q.id,
    answer      : answersMap[i] ?? null,
  }));

  try {
    const result = await Results.submit(examId, answers, timeTaken);
    window.location.href = 'exam-result.html?id=' + result.result_id;
  } catch (err) {
    toast(err.message || 'Submission failed. Please try again.', 'e');
  }
}
