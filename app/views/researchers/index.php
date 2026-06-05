<?php
// Allow unauthenticated access for registration (mode=add without login)
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? '');
$isRegistering = ($mode === 'add' && !is_logged_in());

if (!$isRegistering) {
    require_login();
}

$currentUser = current_user();

$FACT_CATEGORIES = [
    'Food Security, Nutrition & Health',
    'Ecosystems & Biodiversity',
    'Governance & Innovation',
    'Markets & Trade',
    'Crosscutting Themes'
];

/* ── POST handler ─────────────────────────────────────────────────── */
$registrationError = null;
$registrationFormData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF validation
    if (!verify_csrf()) {
        set_flash('error', 'Security validation failed. Please try again.');
        redirect_to('researchers', ['mode' => 'add']);
    }

    if ($action === 'save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $isNewRegistration = ($id === 0 && !is_logged_in());

            // Authorization checks
            if ($id === 0 && is_logged_in() && !is_admin()) {
                set_flash('error', 'Only admins can add researchers directly.');
                redirect_to('researchers');
            }
            if ($id > 0 && !is_admin()) {
                $ownerCheck = $conn->prepare('SELECT email FROM researchers WHERE id = ? LIMIT 1');
                $ownerCheck->bind_param('i', $id);
                $ownerCheck->execute();
                $ownerRow = $ownerCheck->get_result()->fetch_assoc();
                if (!$ownerRow || strtolower($ownerRow['email']) !== strtolower($currentUser['email'])) {
                    set_flash('error', 'You can only edit your own profile.');
                    redirect_to('researchers');
                }
            }

            $first       = trim($_POST['first_name'] ?? '');
            $last        = trim($_POST['last_name']  ?? '');
            $email       = trim($_POST['email']      ?? '');
            $institution = trim($_POST['institution']?? '');
            $department  = trim($_POST['department'] ?? '');
            $title       = trim($_POST['title']      ?? '');
            $bio         = trim($_POST['bio']        ?? '');

            // Multi-category: stored pipe-separated
            $focusAreaArr = array_values(array_filter(array_map('trim', (array)($_POST['focus_area'] ?? []))));
            $focusArea    = implode('|', $focusAreaArr);

            $focusDetail = '';
            if (isset($_POST['focus_area_detail'])) {
                if (is_array($_POST['focus_area_detail'])) {
                    $focusDetail = implode(', ', array_map('trim', $_POST['focus_area_detail']));
                } else {
                    $focusDetail = trim($_POST['focus_area_detail']);
                }
            }

            $topics       = trim($_POST['topics']    ?? '');
            $geography    = trim($_POST['geography'] ?? '');
            $coAdvising   = isset($_POST['co_advising']) ? 1 : 0;
            $coDetails    = trim($_POST['co_advising_details'] ?? '');
            $profileUrl   = trim($_POST['profile_url']         ?? '');
            $websiteUrl   = trim($_POST['website_url']          ?? '');
            $orcidId      = trim($_POST['orcid_id']             ?? '');
            $googleScholarUrl = trim($_POST['google_scholar_url'] ?? '');
            $notifyMatches    = isset($_POST['notify_matches']) ? 1 : 0;
            $notifyFrequency  = trim($_POST['notify_frequency'] ?? 'immediate');
            if (!in_array($notifyFrequency, ['immediate', 'weekly', 'never'], true)) {
                $notifyFrequency = 'immediate';
            }
            $notifyThreshold  = (int)($_POST['notify_threshold'] ?? 60);
            if (!in_array($notifyThreshold, [40, 60, 80], true)) {
                $notifyThreshold = 60;
            }
            $quietHoursStart = trim($_POST['quiet_hours_start'] ?? '');
            if ($quietHoursStart && !preg_match('/^\d{2}:\d{2}$/', $quietHoursStart)) {
                $quietHoursStart = null;
            }
            $quietHoursEnd = trim($_POST['quiet_hours_end'] ?? '');
            if ($quietHoursEnd && !preg_match('/^\d{2}:\d{2}$/', $quietHoursEnd)) {
                $quietHoursEnd = null;
            }

            // Growth tracking: source and referrer
            $source = in_array($_POST['source'] ?? '', ['google', 'linkedin', 'conference', 'colleague', 'organization', 'social', 'academic', 'other']) ? $_POST['source'] : null;
            $referrerName = null;
            if (in_array($source, ['colleague', 'organization'])) {
                $referrerName = trim($_POST['referrer_name'] ?? '');
                if (empty($referrerName)) {
                    $referrerName = null; // Null if empty
                }
            }

            // For registration errors, store form data and error to show form inline
            $storeFormDataForRegistrationError = function($errorMsg) use ($first, $last, $email, $institution, $department, $title, $bio, $focusAreaArr, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $notifyMatches, $notifyFrequency, $notifyThreshold, $quietHoursStart, $quietHoursEnd) {
                global $registrationError, $registrationFormData;
                $registrationError = $errorMsg;
                $registrationFormData = [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'institution' => $institution,
                    'department' => $department,
                    'title' => $title,
                    'bio' => $bio,
                    'focus_area' => $focusAreaArr,
                    'focus_area_detail' => $focusDetail,
                    'topics' => $topics,
                    'geography' => $geography,
                    'co_advising' => $coAdvising,
                    'co_advising_details' => $coDetails,
                    'profile_url' => $profileUrl,
                    'website_url' => $websiteUrl,
                    'orcid_id' => $orcidId,
                    'google_scholar_url' => $googleScholarUrl,
                    'notify_matches' => $notifyMatches,
                    'notify_frequency' => $notifyFrequency,
                    'notify_threshold' => $notifyThreshold,
                    'quiet_hours_start' => $quietHoursStart,
                    'quiet_hours_end' => $quietHoursEnd,
                    'password' => $_POST['password'] ?? '',
                    'confirm_password' => $_POST['confirm_password'] ?? ''
                ];
                return true; // Signal to continue without registering
            };

            // Validation for registration only
            if ($isNewRegistration) {
                // Rate limit registration by IP
                $rateLimiter = new RateLimiter($conn);
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                if (!$rateLimiter->check('register_' . $ip, 5, 3600)) {
                    $storeFormDataForRegistrationError('Too many registration attempts from your IP. Please try again in an hour.');
                }

                // Also rate limit by email domain
                if (!$registrationError && preg_match('/@(.+)$/i', $email, $m)) {
                    $domain = strtolower($m[1]);
                    if (!$rateLimiter->check('register_domain_' . $domain, 20, 3600)) {
                        $storeFormDataForRegistrationError('Too many registrations from this email domain. Please try again later.');
                    }
                }

                if ($first === '' || $last === '') {
                    $storeFormDataForRegistrationError('First and last name are required.');
                }
                if ($institution === '') {
                    $storeFormDataForRegistrationError('Institution is required.');
                }
                if (strlen($geography) > 500) {
                    $storeFormDataForRegistrationError('Geographic focus cannot exceed 500 characters. Currently: ' . strlen($geography) . ' characters.');
                }
                if (strlen($topics) > 500) {
                    $storeFormDataForRegistrationError('Topics cannot exceed 500 characters. Currently: ' . strlen($topics) . ' characters.');
                }
            }

            // NEW REGISTRATION: create user account + researcher profile
            if ($isNewRegistration) {
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if ($password === '' || $confirmPassword === '') {
                    $storeFormDataForRegistrationError('Password is required.');
                }
                if (!$registrationError && $password !== $confirmPassword) {
                    $storeFormDataForRegistrationError('Passwords do not match.');
                }
                if (!$registrationError && strlen($password) < 8) {
                    $storeFormDataForRegistrationError('Password must be at least 8 characters.');
                }
                if (!$registrationError && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $storeFormDataForRegistrationError('Please enter a valid email address.');
                }

                // Check if user already exists
                if (!$registrationError) {
                    $checkUser = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
                    if (!$checkUser) throw new Exception('Prepare check email failed: ' . $conn->error);
                    $checkUser->bind_param('s', $email);
                    if (!$checkUser->execute()) throw new Exception('Error checking email: ' . $checkUser->error);
                    if ($checkUser->get_result()->num_rows > 0) {
                        $storeFormDataForRegistrationError('This email is already registered.');
                    }
                }

                // Only proceed with user creation if no validation errors
                if (!$registrationError) {
                    // Start transaction — rollback on ANY error
                    $conn->begin_transaction();

                    try {
                        // Create user account
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $userStmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
                        if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                        $role = 'researcher';
                        $status = 'unverified';
                        $fullName = trim("$first $last");
                        $userStmt->bind_param('sssss', $email, $passwordHash, $fullName, $role, $status);
                        if (!$userStmt->execute()) {
                            throw new Exception('Error creating account: ' . $userStmt->error);
                        }
                        $userId = $conn->insert_id;

                    // Create email verification token
                    $token = generate_unique_token($conn);
                    $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                    $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
                    if (!$evStmt) throw new Exception('Prepare email_verifications failed: ' . $conn->error);
                    $evStmt->bind_param('sss', $email, $token, $expiresAt);
                    if (!$evStmt->execute()) {
                        throw new Exception('Error creating verification token: ' . $evStmt->error);
                    }

                    // Create researcher profile linked to user
                    ensure_tags($conn, $topics, 'topic');
                    ensure_tags($conn, $geography, 'geography');

                    $stmt = $conn->prepare('INSERT INTO researchers (user_id, first_name, last_name, email, institution, department, title, bio, focus_area, focus_area_detail, topics, geography, co_advising, co_advising_details, profile_url, website_url, orcid_id, google_scholar_url, status, notify_matches, notify_frequency, notify_threshold, quiet_hours_start, quiet_hours_end, source, referrer_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    if (!$stmt) throw new Exception('Prepare researchers failed: ' . $conn->error);
                    $status_researcher = 'pending_approval';
                    $stmt->bind_param('issssssssissssssissississs', $userId, $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $status_researcher, $notifyMatches, $notifyFrequency, $notifyThreshold, $quietHoursStart, $quietHoursEnd, $source, $referrerName);
                    if (!$stmt->execute()) {
                        throw new Exception('Error creating researcher profile: ' . $stmt->error);
                    }

                    // Generate AI summary and semantic embedding
                    $newResearcherId = $conn->insert_id;
                    generate_researcher_summary($conn, $newResearcherId);

                    // Generate embedding for semantic search (async via job queue)
                    enqueue_job($conn, 'generate_embedding', [
                        'entity_type' => 'researcher',
                        'entity_id' => $newResearcherId
                    ]);

                    // Enqueue ORCID publication fetch if provided
                    if ($orcidId) {
                        enqueue_job($conn, 'fetch_orcid_publications', ['researcher_id' => $newResearcherId, 'orcid_id' => $orcidId]);
                    }

                    // Enqueue verification email
                    @$mailCfg = require __DIR__ . '/../../../config/mail.php';
                    if (!is_array($mailCfg)) $mailCfg = [];
                    $appUrl = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                    $verifyUrl = $appUrl . '/index.php?page=verify&token=' . urlencode($token);
                    $html = mail_tpl_verify_email($verifyUrl, $first);
                    enqueue_job($conn, 'send_notification', [
                        'to' => $email,
                        'subject' => 'Verify your FACT Alliance Hub account',
                        'html' => $html
                    ]);

                        audit($conn, 'researcher_signup', ['type' => 'user', 'id' => $userId, 'email' => $email, 'detail' => "New researcher registration: $fullName"]);

                        // Commit transaction — all operations succeeded
                        $conn->commit();

                        set_flash('success', 'Account created! Check your email to verify your account.');
                        redirect_to('verify', ['e' => $email, 'pending' => '1']);
                    } catch (Exception $e) {
                        // Rollback transaction on ANY error — no user/researcher created
                        $conn->rollback();
                        $storeFormDataForRegistrationError('Registration failed: ' . $e->getMessage());
                    }
                }
            }
            // EXISTING RESEARCHER: update profile
            else if ($id > 0) {
                // Check if email changed and require re-verification
                $existingResearcher = null;
                $check = $conn->prepare('SELECT email FROM researchers WHERE id = ? LIMIT 1');
                $check->bind_param('i', $id);
                $check->execute();
                $existingResearcher = $check->get_result()->fetch_assoc();

                $emailChanged = $existingResearcher && (strtolower($email) !== strtolower($existingResearcher['email']));

                if ($emailChanged) {
                    // Email changed - require reverification
                    $token = generate_unique_token($conn);
                    $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                    $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
                    $evStmt->bind_param('sss', $email, $token, $expiresAt);
                    @$evStmt->execute();

                    // Send verification email
                    @$mailCfg = require __DIR__ . '/../../../config/mail.php';
                    if (!is_array($mailCfg)) $mailCfg = [];
                    $appUrl = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                    $verifyUrl = $appUrl . '/index.php?page=verify&token=' . urlencode($token);
                    $html = mail_tpl_verify_email($verifyUrl, $first);
                    enqueue_job($conn, 'send_notification', [
                        'to' => $email,
                        'subject' => 'Verify your new email address',
                        'html' => $html
                    ]);

                    set_flash('info', 'Your email address has been changed. Please verify your new email by clicking the link sent to ' . h($email));
                    redirect_to('researchers', ['edit' => $id]);
                }

                $stmt = $conn->prepare('UPDATE researchers SET first_name=?, last_name=?, email=?, institution=?, department=?, title=?, bio=?, focus_area=?, focus_area_detail=?, topics=?, geography=?, co_advising=?, co_advising_details=?, profile_url=?, website_url=?, orcid_id=?, google_scholar_url=?, notify_matches=?, notify_frequency=?, notify_threshold=?, quiet_hours_start=?, quiet_hours_end=? WHERE id=?');
                if (!$stmt) throw new Exception('Prepare update failed: ' . $conn->error);
                $stmt->bind_param('sssssssssssisssssisissi', $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $notifyMatches, $notifyFrequency, $notifyThreshold, $quietHoursStart, $quietHoursEnd, $id);
                if (!$stmt->execute()) throw new Exception('Error updating profile: ' . $stmt->error);
                enqueue_job($conn, 'generate_summary', ['entity_type' => 'researcher', 'entity_id' => $id]);
                enqueue_job($conn, 'generate_embedding', ['entity_type' => 'researcher', 'entity_id' => $id]);
                if ($orcidId) {
                    enqueue_job($conn, 'fetch_orcid_publications', ['researcher_id' => $id, 'orcid_id' => $orcidId]);
                }
                set_flash('success', 'Researcher updated.');
            }
            // ADMIN ADDING RESEARCHER
            else {
                ensure_tags($conn, $topics, 'topic');
                ensure_tags($conn, $geography, 'geography');

                // Check if user account exists
                $checkUser = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
                if (!$checkUser) throw new Exception('Prepare checkUser failed: ' . $conn->error);
                $checkUser->bind_param('s', $email);
                if (!$checkUser->execute()) throw new Exception('Error checking user: ' . $checkUser->error);
                $existingUser = $checkUser->get_result()->fetch_assoc();
                $userId = null;

                // Create user account if it doesn't exist
                if (!$existingUser) {
                    $tempPassword = bin2hex(random_bytes(16));
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    $role = 'researcher';
                    $status = 'unverified';
                    $fullName = trim("$first $last");
                    $userStmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
                    if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                    $userStmt->bind_param('sssss', $email, $passwordHash, $fullName, $role, $status);
                    if ($userStmt->execute()) {
                        $userId = $conn->insert_id;
                        // Queue verification email
                        $token = generate_unique_token($conn);
                        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                        $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
                        if ($evStmt) {
                            $evStmt->bind_param('sss', $email, $token, $expiresAt);
                            @$evStmt->execute();
                        }
                    } else {
                        throw new Exception('Error creating user: ' . $userStmt->error);
                    }
                } else {
                    $userId = (int)$existingUser['id'];
                }

                // Create researcher profile
                $stmt = $conn->prepare('INSERT INTO researchers (user_id, first_name, last_name, email, institution, department, title, bio, focus_area, focus_area_detail, topics, geography, co_advising, co_advising_details, profile_url, website_url, orcid_id, google_scholar_url, status, notify_matches, notify_frequency, notify_threshold) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) throw new Exception('Prepare researchers failed: ' . $conn->error);
                $status_researcher = 'active';
                $stmt->bind_param('isssssssssssissssssisi', $userId, $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $status_researcher, $notifyMatches, $notifyFrequency, $notifyThreshold);
                if (!$stmt->execute()) throw new Exception('Error creating profile: ' . $stmt->error);
                $newResearcherId = $conn->insert_id;

                // Generate AI summary and embedding
                generate_researcher_summary($conn, $newResearcherId);
                enqueue_job($conn, 'generate_embedding', [
                    'entity_type' => 'researcher',
                    'entity_id' => $newResearcherId
                ]);

                audit($conn, 'add_researcher', ['type' => 'researcher', 'id' => $newResearcherId, 'email' => $email]);
                set_flash('success', 'Researcher added.' . ($userId ? ' A verification email has been sent.' : ''));
            }
            // Only redirect if no registration error (form will render inline with error if there is one)
            if (!$registrationError) {
                redirect_to('researchers');
            }
        } catch (Throwable $e) {
            error_log('[Researcher Registration Error] ' . $e->getMessage());
            if ($isNewRegistration) {
                // Store error and form data to show form inline
                if (!isset($first)) $first = $_POST['first_name'] ?? '';
                if (!isset($focusAreaArr)) $focusAreaArr = array_values(array_filter(array_map('trim', (array)($_POST['focus_area'] ?? []))));
                $registrationError = 'Registration error: ' . $e->getMessage();
                $registrationFormData = [
                    'first_name' => $first ?? '',
                    'last_name' => $_POST['last_name'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'institution' => $_POST['institution'] ?? '',
                    'department' => $_POST['department'] ?? '',
                    'title' => $_POST['title'] ?? '',
                    'bio' => $_POST['bio'] ?? '',
                    'focus_area' => $focusAreaArr ?? [],
                    'focus_area_detail' => $_POST['focus_area_detail'] ?? '',
                    'topics' => $_POST['topics'] ?? '',
                    'geography' => $_POST['geography'] ?? '',
                    'co_advising' => isset($_POST['co_advising']) ? 1 : 0,
                    'co_advising_details' => $_POST['co_advising_details'] ?? '',
                    'profile_url' => $_POST['profile_url'] ?? '',
                    'website_url' => $_POST['website_url'] ?? '',
                    'orcid_id' => $_POST['orcid_id'] ?? '',
                    'google_scholar_url' => $_POST['google_scholar_url'] ?? '',
                    'notify_matches' => isset($_POST['notify_matches']) ? 1 : 0,
                    'password' => $_POST['password'] ?? '',
                    'confirm_password' => $_POST['confirm_password'] ?? ''
                ];
            } else {
                set_flash('error', 'Error: ' . $e->getMessage());
                redirect_to('researchers');
            }
        }
    }

    if ($action === 'delete') {
        if (!is_admin()) {
            set_flash('error', 'Only admins can delete researchers.');
            redirect_to('researchers');
        }
        $id   = (int)($_POST['id'] ?? 0);
        if (!$id) {
            set_flash('error', 'Invalid researcher ID.');
            redirect_to('researchers');
        }
        try {
            // Soft delete researcher profile
            $stmt = $conn->prepare("UPDATE researchers SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
            $researcherRows = $stmt->affected_rows;

            if ($researcherRows === 0) {
                set_flash('error', 'Researcher not found.');
                redirect_to('researchers');
            }

            // Deactivate linked user account by finding the user_id first
            $findUserStmt = $conn->prepare("SELECT user_id FROM researchers WHERE id = ? LIMIT 1");
            if (!$findUserStmt) throw new Exception('Prepare findUser failed: ' . $conn->error);
            $findUserStmt->bind_param('i', $id);
            if (!$findUserStmt->execute()) throw new Exception('Execute findUser failed: ' . $findUserStmt->error);
            $userRow = $findUserStmt->get_result()->fetch_assoc();

            if ($userRow && $userRow['user_id']) {
                $userId = $userRow['user_id'];
                $userStmt = $conn->prepare("UPDATE users SET status = 'inactive', session_token = NULL, deactivated_at = NOW() WHERE id = ?");
                if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                $userStmt->bind_param('i', $userId);
                if (!$userStmt->execute()) throw new Exception('Execute user failed: ' . $userStmt->error);
            }

            audit($conn, 'delete_researcher', ['type' => 'researcher', 'id' => $id]);
            set_flash('success', 'Researcher deleted successfully.');
        } catch (Exception $e) {
            error_log('[Researcher Delete Error] ' . $e->getMessage());
            set_flash('error', 'Failed to delete researcher: ' . $e->getMessage());
        }
        redirect_to('researchers');
    }
}

/* ── Load data ────────────────────────────────────────────────────── */
$mode   = $_GET['mode']  ?? '';
$editId = (int)($_GET['edit'] ?? 0);
$viewId = (int)($_GET['view'] ?? 0);
$fromSearch = isset($_GET['from_search']) && $_GET['from_search'] === '1';
$searchSessionKey = preg_replace('/[^a-f0-9]/', '', $_GET['s'] ?? '');

// Admin-only checks: admin adding researcher (not user registration)
if ($mode === 'add' && is_logged_in() && !is_admin()) {
    set_flash('error', 'Only admins can add researchers.');
    redirect_to('researchers');
}
if ($editId > 0 && !is_admin()) {
    $editCheck = $conn->prepare('SELECT email FROM researchers WHERE id = ? LIMIT 1');
    $editCheck->bind_param('i', $editId);
    $editCheck->execute();
    $editCheckRow = $editCheck->get_result()->fetch_assoc();
    if (!$editCheckRow || strtolower($editCheckRow['email']) !== strtolower($currentUser['email'])) {
        set_flash('error', 'You can only edit your own profile.');
        redirect_to('researchers');
    }
}

require_once __DIR__ . '/../../core/Paginator.php';

$topicTags = get_all_tags($conn, 'topic');
$researchers = [];

// Pagination setup
$page = max(1, (int)($_GET['p'] ?? 1));
$itemsPerPage = 24;

// Count total active researchers for public browse
$countRes = @$conn->query("SELECT COUNT(*) c FROM researchers WHERE status = 'active' AND deleted_at IS NULL");
if (!$countRes) {
    $countRes = @$conn->query("SELECT COUNT(*) c FROM researchers WHERE status = 'active'");
}
if (!$countRes) {
    $countRes = @$conn->query("SELECT COUNT(*) c FROM researchers");
}
$totalCount = 0;
if ($countRes) {
    $countRow = $countRes->fetch_assoc();
    $totalCount = (int)($countRow['c'] ?? 0);
}

$paginator = new Paginator($totalCount, $itemsPerPage, $page);

// Load current page of researchers (public browse: only active)
$res = @$conn->query("SELECT * FROM researchers WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name ASC, last_name ASC " . $paginator->getSQLLimit());
if (!$res) {
    $res = @$conn->query("SELECT * FROM researchers WHERE status = 'active' ORDER BY first_name ASC, last_name ASC " . $paginator->getSQLLimit());
}
if (!$res) {
    $res = @$conn->query("SELECT * FROM researchers ORDER BY first_name ASC, last_name ASC " . $paginator->getSQLLimit());
}
if ($res) {
    while ($row = $res->fetch_assoc()) $researchers[] = $row;
}

// For display, these are already active researchers
$publicResearchers = $researchers;

// Unique non-empty institutions (sorted)
$institutions = array_values(array_unique(array_filter(array_map(fn($r) => trim($r['institution'] ?? ''), $researchers))));
sort($institutions);

$editing = null;
$isEditingExisting = false;
if ($editId > 0) {
    foreach ($researchers as $r) {
        if ((int)$r['id'] === $editId) {
            $editing = $r;
            $isEditingExisting = true;
            break;
        }
    }
}

// Use form data from POST + inline error (registration validation failure)
if (!$editing && $registrationFormData) {
    $editing = $registrationFormData;
    $mode = 'add'; // Ensure we show registration form
}
// Use form data from session error if available (keeps data on validation errors)
// This is different from editing an existing profile
else if (!$editing && isset($_SESSION['form_data'])) {
    $editing = $_SESSION['form_data'];
    unset($_SESSION['form_data']); // Clear after use
}
$viewing = null;
if ($viewId > 0) foreach ($researchers as $r) if ((int)$r['id'] === $viewId) $viewing = $r;

// Top funding matches for viewing panel (only show if user is approved)
$topFundingMatches = [];
if ($viewing && is_approved()) {
    // Try with deleted_at column first, fall back if it doesn't exist
    $tfStmt = @$conn->prepare(
        'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                fc.id, fc.title, fc.funder, fc.deadline, fc.status
         FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
         WHERE ms.researcher_id = ? AND fc.deleted_at IS NULL
         ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 5'
    );
    if (!$tfStmt) {
        // Fallback if deleted_at column doesn't exist
        $tfStmt = $conn->prepare(
            'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                    fc.id, fc.title, fc.funder, fc.deadline, fc.status
             FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
             WHERE ms.researcher_id = ?
             ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 5'
        );
    }
    if ($tfStmt) {
        $tfStmt->bind_param('i', $viewing['id']); $tfStmt->execute();
        $topFundingMatches = $tfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Load researcher AI summary for viewing panel
$researcherSummary = null;
if ($viewing) {
    $sq = $conn->prepare(
        'SELECT summary, created_at FROM ai_summaries WHERE entity_type=? AND entity_id=? LIMIT 1'
    );
    $sqType = 'researcher';
    $sq->bind_param('si', $sqType, $viewing['id']); $sq->execute();
    $researcherSummary = $sq->get_result()->fetch_assoc();
}

// "For You" recommendations: top funding matches for the logged-in researcher (only if approved)
$myMatches    = [];
$myResearcher = null;
if (!is_admin() && is_approved()) {
    // Try with deleted_at column first, fall back if it doesn't exist
    $meStmt = @$conn->prepare("SELECT id FROM researchers WHERE LOWER(email) = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL LIMIT 1");
    if (!$meStmt) {
        // Fallback if deleted_at column doesn't exist
        $meStmt = $conn->prepare("SELECT id FROM researchers WHERE LOWER(email) = ? AND status IN ('active', 'pending_approval') LIMIT 1");
    }
    if (!$meStmt) {
        // Further fallback if status column doesn't exist
        $meStmt = $conn->prepare("SELECT id FROM researchers WHERE LOWER(email) = ? LIMIT 1");
    }
    if ($meStmt) {
        $meEmail = strtolower($currentUser['email']);
        $meStmt->bind_param('s', $meEmail); $meStmt->execute();
        $meRow = $meStmt->get_result()->fetch_assoc();
        if ($meRow) {
            $myResearcher = $meRow;
            // Try with deleted_at column first, fall back if it doesn't exist
            $myStmt = @$conn->prepare(
                'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                        fc.id, fc.title, fc.funder, fc.deadline, fc.status
                 FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
                 WHERE ms.researcher_id = ? AND fc.deleted_at IS NULL
                 ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 3'
            );
            if (!$myStmt) {
                // Fallback if deleted_at column doesn't exist
                $myStmt = $conn->prepare(
                    'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                            fc.id, fc.title, fc.funder, fc.deadline, fc.status
                     FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
                     WHERE ms.researcher_id = ?
                     ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 3'
                );
            }
            if ($myStmt) {
                $myStmt->bind_param('i', $meRow['id']); $myStmt->execute();
                $myMatches = $myStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }
    }
}
?>
<style>
.score-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
.ai-score { background: #eaf6f0; color: #1a6b5a; }
.kw-score { background: #f0f4f8; color: #374151; }
</style>

<?php if (!$isRegistering): ?>
<!-- ── Page head ────────────────────────────────────────────────── -->
<div class="panel page-head">
    <div class="head-row">
        <h1>Researchers</h1>
        <?php if (is_admin()): ?>
            <a class="primary-btn" href="index.php?page=researchers&mode=add">+ Add Researcher</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($myMatches): ?>
<div class="panel" style="border-left:4px solid var(--primary);padding:16px 20px">
    <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--primary);margin-bottom:10px">Your Top Funding Matches</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <?php foreach ($myMatches as $m):
        $score = $m['score_ai'] ?? $m['score_keyword'];
        $isAi  = $m['score_ai'] !== null; ?>
        <div style="background:#f9fbfa;border:1px solid var(--line);border-radius:10px;padding:12px 14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <span class="badge score-badge <?= $isAi ? 'ai-score' : 'kw-score' ?>">
                    <?= $score ?><?= $isAi ? '%' : ' pts' ?>
                </span>
                <span class="badge <?= status_class($m['status']) ?>"><?= h($m['status']) ?></span>
            </div>
            <div style="font-weight:700;font-size:14px;margin-bottom:2px"><?= h($m['title']) ?></div>
            <div class="muted" style="font-size:12px"><?= h($m['funder']) ?> · <?= h(format_deadline($m['deadline'])) ?></div>
            <?php if ($m['explanation']): ?>
            <p class="muted small" style="margin:6px 0 0;font-style:italic;font-size:12px"><?= h($m['explanation']) ?></p>
            <?php endif; ?>
            <a href="index.php?page=funding&view=<?= (int)$m['id'] ?>"
               style="display:inline-block;margin-top:8px;font-size:12px;font-weight:600;color:var(--primary)">
               View →
            </a>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:10px">
        <a href="index.php?page=matching">See all your matches →</a> · Based on your profile topics and geography.
    </p>
</div>
<?php endif; ?>
<?php endif; // end !$isRegistering ?>

<!-- ── Edit/Registration form ────────────────────────────────────── -->
<?php if ($mode === 'add' || $editing): ?>
<?php
// Handle focus_area as either array (from POST) or string (from DB)
$focusAreaRaw = $editing['focus_area'] ?? '';
if (is_array($focusAreaRaw)) {
    $selectedCats = $focusAreaRaw;
} else {
    $selectedCats = array_values(array_filter(array_map('trim', explode('|', $focusAreaRaw))));
}

// Handle focus_area_detail
$focusDetailRaw = $editing['focus_area_detail'] ?? '';
if (is_array($focusDetailRaw)) {
    $selectedSubcats = $focusDetailRaw;
} else {
    $selectedSubcats = array_values(array_filter(array_map('trim', explode(',', $focusDetailRaw))));
}
?>
<div class="panel modalish">
    <h2><?php
        if ($editing) echo 'Edit Researcher';
        elseif ($mode === 'add' && !is_logged_in()) echo 'Register as Researcher';
        else echo 'Add Researcher';
    ?></h2>
    <?php if ($registrationError): ?>
    <div class="alert alert-error" style="margin-bottom:16px;background:#fff5f5;border-left:4px solid #b54646;padding:12px 16px;border-radius:6px;color:#5a2c2c">
        <strong>Error:</strong> <?= h($registrationError) ?>
    </div>
    <?php endif; ?>
    <form method="post" class="form-grid two">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
        <input type="hidden" name="mode" value="<?= h($mode) ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><?php // Include CSRF token for form submission ?>

        <div><label>First name *</label><input name="first_name" value="<?= h($editing['first_name'] ?? '') ?>" required></div>
        <div><label>Last name *</label><input name="last_name" value="<?= h($editing['last_name'] ?? '') ?>" required></div>
        <div><label>Email<?= ($mode === 'add' && !$editing) ? ' *' : '' ?></label><input name="email" type="email" value="<?= h($editing['email'] ?? '') ?>"<?= ($mode === 'add' && !$editing) ? ' required' : '' ?>></div>

        <?php if ($mode === 'add' && !$isEditingExisting): ?>
        <div><label>Password *</label><input name="password" type="password" id="reg-password" value="<?= h($editing['password'] ?? '') ?>" required placeholder="At least 8 characters"></div>
        <div>
            <label>Confirm Password *</label>
            <input name="confirm_password" type="password" id="reg-confirm-password" value="<?= h($editing['confirm_password'] ?? '') ?>" required placeholder="Re-enter your password">
            <div id="pwd-match-msg" style="font-size:13px;margin-top:4px;display:none"></div>
        </div>
        <?php endif; ?>

        <div><label>Institution <span style="color:#b54646;font-weight:600">*</span></label><input name="institution" value="<?= h($editing['institution'] ?? '') ?>" required></div>
        <div><label>Department</label><input name="department" value="<?= h($editing['department'] ?? '') ?>"></div>
        <div><label>Title</label><input name="title" value="<?= h($editing['title'] ?? '') ?>"></div>
        <div class="span-2"><label>Bio</label><textarea name="bio"><?= h($editing['bio'] ?? '') ?></textarea></div>

        <!-- Category: multi-checkbox -->
        <div class="span-2">
            <label style="display:block;margin-bottom:8px;font-weight:600;">Category <span style="font-weight:400;">(check all that apply)</span></label>
            <div class="cat-chk-grid">
                <?php foreach ($FACT_CATEGORIES as $cat): ?>
                <label class="cat-chk-row">
                    <input type="checkbox" name="focus_area[]" value="<?= h($cat) ?>" <?= in_array($cat, $selectedCats, true) ? 'checked' : '' ?>>
                    <span><?= h($cat) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Subcategory: checkboxes, grouped by selected parent categories -->
        <div class="span-2">
            <label style="display:block;margin-bottom:8px;font-weight:600;">Subcategory <span style="font-weight:400;">(check all that apply)</span></label>
            <div id="subcategory-checkboxes" class="subcategory-grid">
                <span class="muted">-- choose a category above first --</span>
            </div>
        </div>

        <div><label>Topics (comma-separated)</label><input name="topics" maxlength="500" value="<?= h($editing['topics'] ?? '') ?>" title="Maximum 500 characters"></div>
        <div><label>Geographic focus (comma-separated)</label><input name="geography" maxlength="500" value="<?= h($editing['geography'] ?? '') ?>" title="Maximum 500 characters"></div>

        <?php if ($mode === 'add' && !$isEditingExisting): ?>
        <!-- Growth tracking: only show on registration, not profile edits -->
        <div><label>How did you hear about us?</label>
            <select name="source" id="source-select" style="width:100%;">
                <option value="">-- Select --</option>
                <option value="google">Google/Web Search</option>
                <option value="linkedin">LinkedIn</option>
                <option value="conference">Conference/Event</option>
                <option value="colleague">Colleague Referral</option>
                <option value="organization">Organization Referral</option>
                <option value="social">Social Media</option>
                <option value="academic">Academic Network</option>
                <option value="other">Other</option>
            </select>
        </div>

        <!-- Referrer name — shows only for referral options -->
        <div id="referrer-box" style="display:none;grid-column:span 2;">
            <div style="padding:12px;background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px;">
                <label>Who referred you? <span style="font-size:12px;color:#6b7280;font-weight:400;display:block;margin-top:4px;">Please let us know their name so we can acknowledge them</span></label>
                <input type="text" name="referrer_name" id="referrer_name" placeholder="Name of colleague or organization" maxlength="255" style="margin-top:6px;width:100%;">
            </div>
        </div>
        <?php endif; ?>

        <div class="span-2">
            <div class="coadvising-section">
                <label class="coadvising-toggle">
                    <input type="checkbox" id="co_advising" name="co_advising" <?= !empty($editing['co_advising']) ? 'checked' : '' ?>>
                    <span class="coadvising-label">Open to co-advising students</span>
                </label>
                <div class="coadvising-details-wrap" id="coadvising-details-wrap" style="<?= !empty($editing['co_advising']) ? '' : 'display:none' ?>;margin-top:12px;overflow:hidden;transition:all .2s">
                    <label for="co_advising_details">Co-advising details</label>
                    <textarea id="co_advising_details" name="co_advising_details" placeholder="Describe your interests, topics, or preferences for co-advising..."><?= h($editing['co_advising_details'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="span-2">
            <div class="links-card">
                <div class="links-card-title">Links &amp; Profiles</div>
                <div class="links-grid-inner">
                    <div><label>Institutional Profile URL</label><input name="profile_url" value="<?= h($editing['profile_url'] ?? '') ?>" placeholder="https://..."></div>
                    <div><label>Research / Lab Website</label><input name="website_url" value="<?= h($editing['website_url'] ?? '') ?>" placeholder="https://..."></div>
                    <div><label>ORCID</label><input name="orcid_id" value="<?= h($editing['orcid_id'] ?? '') ?>" placeholder="0000-0001-2345-6789"></div>
                    <div><label>Google Scholar URL</label><input name="google_scholar_url" value="<?= h($editing['google_scholar_url'] ?? '') ?>" placeholder="https://scholar.google.com/..."></div>
                </div>
            </div>
        </div>

        <div class="span-2">
            <div style="background:#f8fafb;border:1.5px solid #dde6dd;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <input type="checkbox" id="notify_matches" name="notify_matches"
                       <?= !empty($editing['notify_matches']) ? 'checked' : '' ?>
                       style="width:17px;height:17px;accent-color:#1a6b5a;flex-shrink:0;cursor:pointer">
                <div style="flex:1">
                    <label for="notify_matches" style="margin:0;font-size:13.5px;font-weight:600;color:#374151;cursor:pointer;line-height:1.4">
                        Email me when new funding calls match my research profile
                        <span style="display:block;font-weight:400;color:#9aaba4;font-size:12.5px;margin-top:1px">You can unsubscribe at any time via the link in the email.</span>
                    </label>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #dde6dd">
                        <label for="notify_frequency" style="font-size:12.5px;font-weight:600;color:#374151;display:block;margin-bottom:6px">Notification frequency:</label>
                        <select id="notify_frequency" name="notify_frequency" style="padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px;background:white;color:#374151;cursor:pointer;width:100%;max-width:220px">
                            <option value="immediate" <?= ($editing['notify_frequency'] ?? 'immediate') === 'immediate' ? 'selected' : '' ?>>Immediately</option>
                            <option value="weekly" <?= ($editing['notify_frequency'] ?? 'immediate') === 'weekly' ? 'selected' : '' ?>>Weekly digest</option>
                            <option value="never" <?= ($editing['notify_frequency'] ?? 'immediate') === 'never' ? 'selected' : '' ?>>Never</option>
                        </select>
                    </div>
                    <div style="margin-top:12px">
                        <label for="notify_threshold" style="font-size:12.5px;font-weight:600;color:#374151;display:block;margin-bottom:6px">Match relevance threshold:</label>
                        <select id="notify_threshold" name="notify_threshold" style="padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px;background:white;color:#374151;cursor:pointer;width:100%;max-width:220px">
                            <option value="40" <?= ($editing['notify_threshold'] ?? '60') === '40' ? 'selected' : '' ?>>40% (more matches)</option>
                            <option value="60" <?= ($editing['notify_threshold'] ?? '60') === '60' ? 'selected' : '' ?>>60% (balanced)</option>
                            <option value="80" <?= ($editing['notify_threshold'] ?? '60') === '80' ? 'selected' : '' ?>>80% (high relevance only)</option>
                        </select>
                        <div style="font-size:11.5px;color:#9aaba4;margin-top:4px">Only get notified about funding calls that match this well</div>
                    </div>
                    <div style="margin-top:12px">
                        <label style="font-size:12.5px;font-weight:600;color:#374151;display:block;margin-bottom:8px">Quiet hours <span style="color:#9aaba4;font-weight:400">(optional)</span></label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="time" id="quiet_hours_start" name="quiet_hours_start"
                                   value="<?= $editing['quiet_hours_start'] ? h($editing['quiet_hours_start']) : '' ?>"
                                   style="padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px;background:white;color:#374151;flex:1">
                            <span style="color:#9aaba4">to</span>
                            <input type="time" id="quiet_hours_end" name="quiet_hours_end"
                                   value="<?= $editing['quiet_hours_end'] ? h($editing['quiet_hours_end']) : '' ?>"
                                   style="padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px;background:white;color:#374151;flex:1">
                        </div>
                        <div style="font-size:11.5px;color:#9aaba4;margin-top:4px">Notifications won't be sent outside these hours (urgent calls with < 30 days override this)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="span-2 actions-row">
            <button class="primary-btn" type="submit">
                <?php
                    if ($mode === 'add' && !$editing && !is_logged_in()) {
                        echo 'Register';
                    } elseif ($editing) {
                        echo 'Save Changes';
                    } else {
                        echo 'Save';
                    }
                ?>
            </button>
            <a class="ghost-btn" href="index.php?page=researchers">Cancel</a>
        </div>
    </form>
</div>

<style>
.subcategory-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:6px 28px}
.subcategory-grid label{display:flex;align-items:flex-start;gap:10px;font-size:13.5px;font-weight:500;background:#f8f9fa;border-radius:6px;padding:6px 12px;margin:0;cursor:pointer;transition:background .15s}
.subcategory-grid label:hover{background:#e6f0fa}
.subcategory-grid input[type="checkbox"]{margin-top:2px;accent-color:#2a7ae2;width:16px;height:16px;flex-shrink:0;cursor:pointer}
.coadvising-section{background:#f6fbf7;border:1.5px solid #d2e7d7;border-radius:10px;padding:16px 20px}
.coadvising-toggle{display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;font-weight:normal}
.coadvising-toggle input[type="checkbox"]{width:18px;height:18px;margin:0;flex-shrink:0;accent-color:#1a6b5a;cursor:pointer}
.coadvising-label{font-size:1.05em;font-weight:600;color:#205c3b}
.coadvising-details-wrap{display:flex;flex-direction:column;gap:6px}
.coadvising-details-wrap label{font-size:.95em;font-weight:600;color:#205c3b;margin:0}
.coadvising-details-wrap textarea{min-height:72px;border-radius:8px;border:1px solid #c7e2d0;background:#fff;resize:vertical}
.links-card{background:#f8fafb;border:1.5px solid #dde6dd;border-radius:10px;padding:16px 20px}
.links-card-title{font-size:.82em;font-weight:800;letter-spacing:.1em;color:#60706a;text-transform:uppercase;margin-bottom:14px}
.links-grid-inner{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:640px){.links-grid-inner{grid-template-columns:1fr}}
</style>

<script>
const subcategories = <?= json_encode(array_combine(
    array_keys(array_fill_keys($FACT_CATEGORIES, null)),
    array_map(fn($cat) => match($cat) {
        'Food Security, Nutrition & Health' => ['Diet diversity','Affordability of Healthy and Sustainable Diets','Food environments','Forecasting food insecurity/shocks/vulnerabilities','Food issues requiring solutions outside the food system','Alternative proteins','Migration & displacement','Food system tipping points','Safe working environments'],
        'Ecosystems & Biodiversity' => ['Orphan crops','Water management','Biome Specialization: Drylands','Biome Specialization: Forests','Biome Specialization: Aquatic','Biome Specialization: Grasslands','Biome Specialization: Wetlands','Biome Specialization: Other','Ecosystem services','Ecosystem restoration','Nexus approaches','Commercial forestry','Pests & diseases','Soils'],
        'Governance & Innovation' => ['Innovation and change','Political economy (practices, institutions, power and/or politics)','Human mobility','Geopolitical crises','Regulatory/reporting frameworks/standards & certification','Land use and land access'],
        'Markets & Trade' => ['Supply chain risks','Inclusive & resilient value chains','Food industry sustainability tracking','Trade networks & shock propagation','Food trade early warning systems','Energy use'],
        'Crosscutting Themes' => ['Climate change','Diversity, gender, equity, and inclusion','Social-ecological systems','Finance and resource mobilization','Co-development of knowledge/co-design','Data ecosystems','Business models','AI / machine learning and digital tools','Stress testing the global/national food system','Scenarios and storylines','Transition management'],
        default => []
    }, $FACT_CATEGORIES)
)) ?>;

function populateSubcategories(selectedCats, keepSubcats = []) {
    const div = document.getElementById('subcategory-checkboxes');
    div.innerHTML = '';
    if (!selectedCats.length) {
        div.innerHTML = '<span class="muted">-- choose a category above first --</span>';
        return;
    }
    for (const cat of selectedCats) {
        const subs = subcategories[cat];
        if (!subs || !subs.length) continue;
        if (selectedCats.length > 1) {
            const hd = document.createElement('div');
            hd.className = 'subcat-grp-hd';
            hd.textContent = cat;
            div.appendChild(hd);
        }
        for (const sub of subs) {
            const id  = 'subcat_' + sub.replace(/[^a-zA-Z0-9]/g, '_');
            const lbl = document.createElement('label');
            lbl.setAttribute('for', id);
            const chk = document.createElement('input');
            chk.type  = 'checkbox';
            chk.name  = 'focus_area_detail[]';
            chk.value = sub;
            chk.id    = id;
            if (keepSubcats.includes(sub)) chk.checked = true;
            lbl.appendChild(chk);
            const sp = document.createElement('span'); sp.textContent = sub;
            lbl.appendChild(sp);
            div.appendChild(lbl);
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const catChks = [...document.querySelectorAll('.cat-chk-row input[type="checkbox"]')];
    const getSelCats = () => catChks.filter(c => c.checked).map(c => c.value);
    const initSubcats = <?= json_encode($selectedSubcats) ?>;

    populateSubcategories(getSelCats(), initSubcats);

    catChks.forEach(chk => chk.addEventListener('change', () => {
        // Preserve currently-checked subcats across category change
        const curChecked = [...document.querySelectorAll('#subcategory-checkboxes input:checked')].map(c => c.value);
        populateSubcategories(getSelCats(), curChecked);
    }));

    const cb = document.getElementById('co_advising');
    const wrap = document.getElementById('coadvising-details-wrap');
    if (cb && wrap) {
        cb.addEventListener('change', function() {
            wrap.style.display = this.checked ? '' : 'none';
            if (this.checked) wrap.querySelector('textarea').focus();
        });
    }

    // Source dropdown: show referrer name field for referral options
    const sourceSelect = document.getElementById('source-select');
    const referrerBox = document.getElementById('referrer-box');
    const referrerInput = document.getElementById('referrer_name');

    if (sourceSelect && referrerBox && referrerInput) {
        function updateReferrerField() {
            const selected = sourceSelect.value;
            if (selected === 'colleague' || selected === 'organization') {
                referrerBox.style.display = 'block';
                referrerInput.required = true;
            } else {
                referrerBox.style.display = 'none';
                referrerInput.required = false;
                referrerInput.value = ''; // Clear if hidden
            }
        }
        sourceSelect.addEventListener('change', updateReferrerField);
    }

    // Password match validation (as you type)
    const pwdInput = document.getElementById('reg-password');
    const confirmInput = document.getElementById('reg-confirm-password');
    const msgDiv = document.getElementById('pwd-match-msg');

    if (pwdInput && confirmInput && msgDiv) {
        function validatePasswords() {
            if (confirmInput.value === '') {
                msgDiv.style.display = 'none';
                return;
            }
            msgDiv.style.display = 'block';
            if (pwdInput.value === confirmInput.value) {
                msgDiv.textContent = '✓ Passwords match';
                msgDiv.style.color = '#15803d';
            } else {
                msgDiv.textContent = '✗ Passwords do not match';
                msgDiv.style.color = '#b54646';
            }
        }
        pwdInput.addEventListener('input', validatePasswords);
        confirmInput.addEventListener('input', validatePasswords);
    }
});
</script>
<?php endif; ?>

<!-- ── View panel ─────────────────────────────────────────────────── -->
<?php if (!$isRegistering && $viewing): ?>
<div class="panel modalish">
    <div class="head-row">
        <div>
            <h2 style="margin-bottom:2px"><?= h(trim(($viewing['first_name'] ?? '') . ' ' . ($viewing['last_name'] ?? ''))) ?></h2>
            <?php if ($viewing['title']): ?><div class="muted" style="font-size:14px"><?= h($viewing['title']) ?><?= $viewing['institution'] ? ' · ' . h($viewing['institution']) : '' ?></div><?php endif; ?>
        </div>
        <a class="ghost-btn" href="<?= $fromSearch ? 'index.php?page=search' . ($searchSessionKey ? '&s=' . h($searchSessionKey) : '') : 'index.php?page=researchers' ?>">
            <?= $fromSearch ? '← Back to Search' : 'Close' ?>
        </a>
    </div>
    <div class="detail-grid" style="margin-top:14px">
        <?php if ($viewing['institution']): ?><div><strong>Institution:</strong> <?= h($viewing['institution']) ?></div><?php endif; ?>
        <?php if ($viewing['department']):  ?><div><strong>Department:</strong>  <?= h($viewing['department']) ?></div><?php endif; ?>
        <?php if ($viewing['email']):       ?><div><strong>Email:</strong> <a href="mailto:<?= h($viewing['email']) ?>"><?= h($viewing['email']) ?></a></div><?php endif; ?>
        <?php if ($viewing['focus_area']):
            $displayCats = implode(', ', array_filter(array_map('trim', explode('|', $viewing['focus_area']))));
        ?><div><strong>Focus Area:</strong> <?= h($displayCats) ?></div><?php endif; ?>
        <?php if ($viewing['focus_area_detail']): ?>
            <div class="span-detail"><strong>Specializations:</strong> <?= h($viewing['focus_area_detail']) ?></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($viewing['co_advising'])): ?>
    <div style="margin:12px 0 4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="badge" style="background:#eef9f3;border:1px solid #d2e7d7;color:#1a6b5a;font-size:13px;padding:6px 12px">✓ Open to co-advising</span>
        <?php if ($viewing['co_advising_details']): ?><span class="muted" style="font-size:13px"><?= h($viewing['co_advising_details']) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($viewing['bio']): ?><p class="muted block" style="margin-top:12px"><?= nl2br(h($viewing['bio'])) ?></p><?php endif; ?>
    <?php if ($researcherSummary): ?>
    <div style="margin-top:12px;padding:12px 14px;background:#eaf6f0;border:1px solid #c3dfd0;border-radius:10px">
        <div style="font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#1a6b5a;margin-bottom:6px">AI Summary</div>
        <p style="margin:0;font-size:14px;line-height:1.6;color:#1c2a24"><?= h($researcherSummary['summary']) ?></p>
    </div>
    <?php endif; ?>
    <div class="tag-row"><?php foreach (parse_tags($viewing['topics']) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
    <div class="tag-row"><?php foreach (parse_tags($viewing['geography']) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
    <?php if ($topFundingMatches): ?>
    <div style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Top Matching Funding Calls</div>
        <?php foreach ($topFundingMatches as $tf):
            $score = $tf['score_ai'] ?? $tf['score_keyword'];
            $isAi  = $tf['score_ai'] !== null;
        ?>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f4f4f4">
            <span class="badge score-badge <?= $isAi ? 'ai-score' : 'kw-score' ?>"><?= $score ?><?= $isAi ? '%' : ' pts' ?></span>
            <div style="flex:1;min-width:0">
                <a href="index.php?page=funding&view=<?= (int)$tf['id'] ?>" style="font-weight:600;font-size:14px"><?= h($tf['title']) ?></a><br>
                <span class="muted" style="font-size:12px"><?= h($tf['funder']) ?> · <?= h($tf['deadline']) ?></span>
                <?php if ($tf['explanation']): ?><p class="muted small" style="margin:2px 0 0;font-style:italic"><?= h($tf['explanation']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    $profileLinks = [];
    if (!empty($viewing['profile_url']))        $profileLinks['Institutional Profile'] = $viewing['profile_url'];
    if (!empty($viewing['website_url']))         $profileLinks['Research Website']      = $viewing['website_url'];
    if (!empty($viewing['google_scholar_url'])) $profileLinks['Google Scholar']         = $viewing['google_scholar_url'];
    $orcid = $viewing['orcid_id'] ?? '';
    if ($profileLinks || $orcid): ?>
    <div class="profile-links-row">
        <?php foreach ($profileLinks as $label => $url): ?>
            <a class="profile-link-btn" href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($label) ?> ↗</a>
        <?php endforeach; ?>
        <?php if ($orcid): ?>
            <a class="profile-link-btn orcid-btn" href="https://orcid.org/<?= h($orcid) ?>" target="_blank" rel="noopener">ORCID ↗</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── LinkedIn-style layout ─────────────────────────────────────── -->
<?php if (!$isRegistering): ?>
<div class="lk-overlay" id="lk-overlay"></div>

<div class="lk-layout">
    <!-- Sidebar -->
    <aside class="lk-sidebar" id="lk-sidebar">
        <div class="lk-sidebar-head">
            <span class="lk-sidebar-title">Filters</span>
            <button class="lk-clear-btn" data-fp="clear" type="button">Clear all</button>
        </div>

        <!-- Search -->
        <div class="lk-search-wrap">
            <input type="text" data-fp="search" placeholder="Name, institution, topics…" autocomplete="off">
        </div>

        <!-- Category -->
        <div class="lk-section">
            <div class="lk-section-hd">Category</div>
            <div class="lk-section-body">
                <?php foreach ($FACT_CATEGORIES as $cat): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="cats" value="<?= h($cat) ?>">
                    <span><?= h($cat) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Subcategory (grouped, shown based on selected cats) -->
        <div class="lk-section">
            <div class="lk-section-hd">Subcategory</div>
            <div class="lk-section-body">
                <?php foreach ($FACT_CATEGORIES as $cat):
                    $subs = match($cat) {
                        'Food Security, Nutrition & Health' => ['Diet diversity','Affordability of Healthy and Sustainable Diets','Food environments','Forecasting food insecurity/shocks/vulnerabilities','Food issues requiring solutions outside the food system','Alternative proteins','Migration & displacement','Food system tipping points','Safe working environments'],
                        'Ecosystems & Biodiversity' => ['Orphan crops','Water management','Biome Specialization: Drylands','Biome Specialization: Forests','Biome Specialization: Aquatic','Biome Specialization: Grasslands','Biome Specialization: Wetlands','Biome Specialization: Other','Ecosystem services','Ecosystem restoration','Nexus approaches','Commercial forestry','Pests & diseases','Soils'],
                        'Governance & Innovation' => ['Innovation and change','Political economy (practices, institutions, power and/or politics)','Human mobility','Geopolitical crises','Regulatory/reporting frameworks/standards & certification','Land use and land access'],
                        'Markets & Trade' => ['Supply chain risks','Inclusive & resilient value chains','Food industry sustainability tracking','Trade networks & shock propagation','Food trade early warning systems','Energy use'],
                        'Crosscutting Themes' => ['Climate change','Diversity, gender, equity, and inclusion','Social-ecological systems','Finance and resource mobilization','Co-development of knowledge/co-design','Data ecosystems','Business models','AI / machine learning and digital tools','Stress testing the global/national food system','Scenarios and storylines','Transition management'],
                        default => []
                    };
                ?>
                <span class="lk-grp-label"><?= h($cat) ?></span>
                <?php foreach ($subs as $sub): ?>
                <label class="lk-row" data-subcat-parent="<?= h($cat) ?>">
                    <input type="checkbox" data-fp-group="subcats" value="<?= h($sub) ?>">
                    <span><?= h($sub) ?></span>
                </label>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Geography (populated by JS) -->
        <div class="lk-section">
            <div class="lk-section-hd">Geography</div>
            <div class="lk-section-body">
                <input type="text" class="lk-geo-search" placeholder="Search regions & countries…" autocomplete="off">
                <div id="lk-geo-items"></div>
            </div>
        </div>

        <!-- Topics -->
        <?php if ($topicTags): ?>
        <div class="lk-section">
            <div class="lk-section-hd">Topics</div>
            <div class="lk-section-body">
                <?php foreach ($topicTags as $tag): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="topics" value="<?= h(strtolower($tag)) ?>">
                    <span><?= h($tag) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Institution -->
        <?php if ($institutions): ?>
        <div class="lk-section">
            <div class="lk-section-hd">Institution</div>
            <div class="lk-section-body">
                <?php foreach ($institutions as $inst): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="institutions" value="<?= h(strtolower($inst)) ?>">
                    <span><?= h($inst) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Co-advising toggle -->
        <div class="lk-toggle-section">
            <label class="lk-toggle-row">
                <span class="lk-toggle-label">Open to co-advising only</span>
                <span class="lk-sw">
                    <input type="checkbox" data-fp="coadvising">
                    <span class="lk-sw-track"></span>
                </span>
            </label>
        </div>
    </aside>

    <!-- Results -->
    <div class="lk-results">
        <div class="lk-results-head">
            <span class="lk-count" id="lk-count"><?= count($researchers) ?> result<?= count($researchers) !== 1 ? 's' : '' ?></span>
            <button class="lk-mobile-btn" id="lk-mobile-btn" type="button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="12" y1="18" x2="20" y2="18"/></svg>
                Filters
            </button>
        </div>

        <?php if (!$researchers): ?>
        <div class="empty-state panel">No researchers found.</div>
        <?php endif; ?>

        <?php foreach ($publicResearchers as $r):
            $rCats    = array_values(array_filter(array_map('trim', explode('|', $r['focus_area'] ?? ''))));
            $rSubcats = array_values(array_filter(array_map('trim', explode(',', $r['focus_area_detail'] ?? ''))));
            $rTopics  = parse_tags($r['topics'] ?? '');
            $rGeos    = parse_tags($r['geography'] ?? '');
            $filterData = json_encode([
                'name'       => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'institution'=> $r['institution'] ?? '',
                'cats'       => $rCats,
                'subcats'    => $rSubcats,
                'topics'     => $rTopics,
                'geos'       => $rGeos,
                'coadvising' => !empty($r['co_advising']),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
        ?>
        <div class="panel list-card" data-filter="<?= h($filterData) ?>">
            <div class="card-row">
                <div class="card-main">
                    <div class="title-line">
                        <h3><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></h3>
                        <?php if (!empty($r['co_advising'])): ?><span class="badge badge-outline">Co-advising</span><?php endif; ?>
                    </div>
                    <div class="muted"><?= h(implode(' · ', array_filter([$r['title'] ?? '', $r['institution'] ?? '']))) ?></div>
                    <?php if ($rCats): ?>
                    <div class="mini-label">Category:</div>
                    <div class="tag-row">
                        <?php foreach ($rCats as $cat): ?>
                        <span class="tag" style="background:#eef3ff;color:#3b5bdb;border-color:#c5d0f5"><?= h($cat) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mini-label">Topics:</div>
                    <div class="tag-row"><?php foreach (array_slice($rTopics, 0, 4) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
                    <div class="mini-label">Geography:</div>
                    <div class="tag-row"><?php foreach (array_slice($rGeos, 0, 4) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
                </div>
                <div class="card-actions">
                    <a class="ghost-btn" href="index.php?page=researchers&view=<?= (int)$r['id'] ?>">View</a>
                    <?php $isOwnProfile = strtolower($r['email'] ?? '') === strtolower($currentUser['email']); ?>
                    <?php if (is_admin() || $isOwnProfile): ?>
                        <a class="ghost-btn" href="index.php?page=researchers&edit=<?= (int)$r['id'] ?>"><?= $isOwnProfile && !is_admin() ? 'Edit my profile' : 'Edit' ?></a>
                    <?php endif; ?>
                    <?php if (is_admin()): ?>
                        <form method="post" onsubmit="return confirm('Delete researcher?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?= csrf_input() ?><button class="danger-btn" type="submit">Delete</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php require __DIR__ . '/../components/pagination.php';
        render_pagination($paginator, 'p', 'index.php?page=researchers', array_filter($_GET, fn($k) => !in_array($k, ['page', 'p']), ARRAY_FILTER_USE_KEY));
        ?>
    </div><!-- .lk-results -->
</div><!-- .lk-layout -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate geography section from WB_GEO
    const geoContainer = document.getElementById('lk-geo-items');
    if (geoContainer) {
        // Regions first
        const regHd = document.createElement('span');
        regHd.className = 'lk-grp-label';
        regHd.dataset.geoHd = 'Regions';
        regHd.textContent = 'Regions';
        geoContainer.appendChild(regHd);
        for (const region of Object.keys(WB_GEO)) {
            const lbl = document.createElement('label');
            lbl.className = 'lk-row';
            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.dataset.fpGroup  = 'geos';
            chk.dataset.geoGroup = region;
            chk.value = region;
            const sp = document.createElement('span'); sp.textContent = region;
            lbl.append(chk, sp);
            geoContainer.appendChild(lbl);
        }
        // Countries grouped by region
        for (const [region, countries] of Object.entries(WB_GEO)) {
            const hd = document.createElement('span');
            hd.className = 'lk-grp-label';
            hd.dataset.geoHd = region;
            hd.textContent = region;
            geoContainer.appendChild(hd);
            for (const c of countries) {
                const lbl = document.createElement('label');
                lbl.className = 'lk-row';
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.dataset.fpGroup  = 'geos';
                chk.dataset.geoGroup = region;
                chk.value = c;
                const sp = document.createElement('span'); sp.textContent = c;
                lbl.append(chk, sp);
                geoContainer.appendChild(lbl);
            }
        }
    }

    // Mobile sidebar open/close
    const sidebar  = document.getElementById('lk-sidebar');
    const overlay  = document.getElementById('lk-overlay');
    const mobileBtn = document.getElementById('lk-mobile-btn');
    if (mobileBtn && sidebar && overlay) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.add('is-open');
            overlay.classList.add('is-open');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-open');
        });
    }

    // Init FilterPanel (geo items must be in DOM first)
    new FilterPanel({
        sidebarSel: '#lk-sidebar',
        cardSel:    '.lk-results .list-card',
        countSel:   '#lk-count',
        storageKey: 'facthub_researcher_filters'
    });

    // Auto-refresh page after deletion success
    const successAlerts = document.querySelectorAll('[class*="alert-success"]');
    if (successAlerts.length > 0) {
        successAlerts.forEach(alert => {
            if (alert.textContent.includes('deleted') || alert.textContent.includes('Trash')) {
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        });
    }
});
</script>
<?php endif; // end !$isRegistering ?>
