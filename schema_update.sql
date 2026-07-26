/* schema_update.sql (phpMyAdmin 등에서 실행) */

-- 1. 일일 업무 및 차주 계획 테이블
CREATE TABLE IF NOT EXISTS report_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_date DATE NOT NULL,
    company_name VARCHAR(100),
    plan_content TEXT,
    result_content TEXT,
    task_type ENUM('plan', 'actual') DEFAULT 'actual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. 전자결재 문서 (품의, 기안, 연차 등)
CREATE TABLE IF NOT EXISTS e_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id INT NOT NULL,
    doc_type ENUM('proposal', 'draft', 'leave') NOT NULL, -- 품의, 기안, 연차
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    leave_start DATE NULL, -- 연차일 경우 시작일
    leave_end DATE NULL,   -- 연차일 경우 종료일
    status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. 전자결재 결재선 (Approval Line)
CREATE TABLE IF NOT EXISTS e_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    approver_id INT NOT NULL,
    step_order INT NOT NULL, -- 결재 순서 (1차, 2차 등)
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    comment TEXT, -- 반려 사유 또는 승인 코멘트
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (document_id) REFERENCES e_documents(id) ON DELETE CASCADE
);

-- 4. 통합 첨부파일 테이블 (업무보고, 결재 공용)
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_type ENUM('task', 'document') NOT NULL, -- 어디에 첨부된 파일인지
    reference_id INT NOT NULL,                        -- 해당 업무/문서의 ID
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


/* 1. 하위 직원의 보고서를 볼 수 있는 권한 매핑 (예: 김부장(1) -> 고과장(2) ) */
CREATE TABLE IF NOT EXISTS user_relations (
    viewer_id INT NOT NULL, -- 보는 사람 (팀장, 부장 등)
    target_id INT NOT NULL, -- 대상자 (팀원)
    PRIMARY KEY (viewer_id, target_id)
);

/* 2. 결재선 템플릿 (관리자가 미리 지정해둔 결재 경로) */
CREATE TABLE IF NOT EXISTS approval_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,     -- 예: "소모품 구매 결재선 (팀장->이사)"
    step1_id INT NULL,              -- 1차 결재자 UID
    step2_id INT NULL,              -- 2차 결재자 UID
    step3_id INT NULL               -- 3차 결재자 UID
);