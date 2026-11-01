CREATE TABLE IF NOT EXISTS payroll_employees (
    id VARCHAR(36) PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    role VARCHAR(100) NOT NULL DEFAULT '',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    salary_type VARCHAR(20) NOT NULL DEFAULT 'hourly',
    hourly_rate BIGINT NOT NULL DEFAULT 0,
    monthly_salary BIGINT NOT NULL DEFAULT 0,
    expected_days INT NOT NULL DEFAULT 26,
    note TEXT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_periods (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    version INT NOT NULL DEFAULT 1,
    created_by VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL,
    locked_by VARCHAR(36) NULL,
    locked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_entries (
    id VARCHAR(36) PRIMARY KEY,
    period_id VARCHAR(36) NOT NULL,
    employee_id VARCHAR(36) NOT NULL,
    employee_code VARCHAR(40) NOT NULL,
    employee_name VARCHAR(150) NOT NULL,
    role VARCHAR(100) NOT NULL,
    details LONGTEXT NOT NULL,
    minutes INT NOT NULL DEFAULT 0,
    working_days INT NOT NULL DEFAULT 0,
    base_salary BIGINT NOT NULL DEFAULT 0,
    allowance_total BIGINT NOT NULL DEFAULT 0,
    deduction_total BIGINT NOT NULL DEFAULT 0,
    net_salary BIGINT NOT NULL DEFAULT 0,
    UNIQUE KEY payroll_period_employee (period_id, employee_id),
    CONSTRAINT payroll_entry_period FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    CONSTRAINT payroll_entry_employee FOREIGN KEY (employee_id) REFERENCES payroll_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
