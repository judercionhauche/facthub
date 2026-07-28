<?php
/**
 * Admin API for Research Projects & Publications Management
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/core/helpers.php';
require_once __DIR__ . '/../app/services/ResearchPublicationsService.php';

init_session();

// Only admins can access
if (!is_user_logged_in() || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$service = new ResearchPublicationsService($conn);
$method = $_POST['method'] ?? $_GET['method'] ?? null;
$type = $_POST['type'] ?? $_GET['type'] ?? null;  // 'research' or 'publication'

try {
    switch ($method) {
        // ─────────────────────── RESEARCH PROJECTS ───────────────────────
        case 'research_create':
            $id = $service->createResearchProject($_POST);
            if ($id) {
                // Add team members if provided
                if (!empty($_POST['team_members'])) {
                    $order = 0;
                    foreach (explode(',', $_POST['team_members']) as $researcherId) {
                        $researcherId = (int)trim($researcherId);
                        if ($researcherId > 0) {
                            $service->addResearchTeamMember($id, $researcherId, $order++);
                        }
                    }
                }
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to create research project']);
            }
            break;

        case 'research_update':
            $id = (int)$_POST['id'];
            if ($service->updateResearchProject($id, $_POST)) {
                // Update team members
                $stmt = $conn->prepare("DELETE FROM research_project_team WHERE research_project_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();

                if (!empty($_POST['team_members'])) {
                    $order = 0;
                    foreach (explode(',', $_POST['team_members']) as $researcherId) {
                        $researcherId = (int)trim($researcherId);
                        if ($researcherId > 0) {
                            $service->addResearchTeamMember($id, $researcherId, $order++);
                        }
                    }
                }
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to update research project']);
            }
            break;

        case 'research_get':
            $id = (int)$_GET['id'];
            $project = $service->getResearchProject($id);
            if ($project) {
                echo json_encode(['success' => true, 'data' => $project]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Research project not found']);
            }
            break;

        case 'research_list':
            $projects = $service->listResearchProjects();
            echo json_encode(['success' => true, 'data' => $projects]);
            break;

        case 'research_delete':
            $id = (int)$_POST['id'];
            if ($service->deleteResearchProject($id)) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to delete research project']);
            }
            break;

        // ─────────────────────── PUBLICATIONS ───────────────────────
        case 'publication_create':
            $id = $service->createPublication($_POST);
            if ($id) {
                // Add team members if provided
                if (!empty($_POST['team_members'])) {
                    $order = 0;
                    foreach (explode(',', $_POST['team_members']) as $researcherId) {
                        $researcherId = (int)trim($researcherId);
                        if ($researcherId > 0) {
                            $service->addPublicationTeamMember($id, $researcherId, $order++);
                        }
                    }
                }
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to create publication']);
            }
            break;

        case 'publication_update':
            $id = (int)$_POST['id'];
            if ($service->updatePublication($id, $_POST)) {
                // Update team members
                $stmt = $conn->prepare("DELETE FROM publication_team WHERE publication_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();

                if (!empty($_POST['team_members'])) {
                    $order = 0;
                    foreach (explode(',', $_POST['team_members']) as $researcherId) {
                        $researcherId = (int)trim($researcherId);
                        if ($researcherId > 0) {
                            $service->addPublicationTeamMember($id, $researcherId, $order++);
                        }
                    }
                }
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to update publication']);
            }
            break;

        case 'publication_get':
            $id = (int)$_GET['id'];
            $publication = $service->getPublication($id);
            if ($publication) {
                echo json_encode(['success' => true, 'data' => $publication]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Publication not found']);
            }
            break;

        case 'publication_list':
            $publications = $service->listPublications();
            echo json_encode(['success' => true, 'data' => $publications]);
            break;

        case 'publication_delete':
            $id = (int)$_POST['id'];
            if ($service->deletePublication($id)) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to delete publication']);
            }
            break;

        // ─────────────────────── SHARED ───────────────────────
        case 'researcher_options':
            $researchers = $service->getResearcherOptions();
            echo json_encode(['success' => true, 'data' => $researchers]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown method']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
