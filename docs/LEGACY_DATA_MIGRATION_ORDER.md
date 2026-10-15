# Legacy Data Migration Order

This document describes the current migration order used by the HR portal legacy data migration area, with the reason for each step.

The backend service processes modules in this order when the migration scope is set to `All`:

```text
1. Departments
2. Job Positions
3. Employees
4. Employee Managers
5. Leave Records
6. Loan Records
```

## Before Starting

- Take a database backup before running any migration.
- Run a dry run first from the Data Migration screen, then run the real import after reviewing the summary.
- Dry run validates and simulates the migration. It does not insert or update records.
- Make sure master data such as roles, leave types, and loan settings already exists.
- Units are managed separately in Admin. The migration can create or resolve units from legacy `unitid`, `unit_id`, `unit_name`, `branch_name`, or `businessunit_name`, but units should be reviewed before employee validation.
- For legacy business unit value `Riyadh`, the system treats it as `Head Office`.

## 1. Departments

Comment: Run this first because job positions and employees depend on departments.

Expected legacy columns:

```text
deptname, deptcode, description, unitid, isactive, headcount_budget
```

Current handling:

- `deptname` is used as the new department `name`.
- `deptcode` is used as the department code where available.
- Duplicate departments from different branches are merged by department name because the new system does not attach departments to units.
- `unitid` is preserved in the department description and can also create/resolve a Unit for future employee branch identification.
- Departments are not linked to units in the new system. Unit assignment is handled on the Employee record.
- If `headcount_budget` is missing, the default value is `5`.

## 2. Job Positions

Comment: Run after Departments so each position can be linked to its department.

Expected legacy columns:

```text
positionname, title, department_code, department, level, min_salary, max_salary, is_active
```

Current handling:

- `positionname` is treated as the job position title.
- `title` is also accepted.
- If department information exists, the position is attached to that department.
- If department is missing or not found, the position is still created without a department.

## 3. Employees

Comment: Run after Departments, Job Positions, and Units are ready.

Expected legacy columns:

```text
empnum, employee_code, firstname, lastname, emailaddress, emppassword,
contactnumber, date_of_joining, department_name, businessunit_name,
position_name, salary
```

Current handling:

- `empnum` is kept as the employee code, with `EMP` added in front.
  - Example: `129` becomes `EMP129`.
- Legacy MD5 password is stored in `legacy_password_md5`; the system uses the legacy hash fallback for login.
- `department_name` is matched to the migrated department.
- `businessunit_name` is matched to Unit.
- `Riyadh` business unit is considered `Head Office`.
- `position_name`, `job_position`, `designation`, or `title` is matched to designation/job position.
- Missing employee status defaults to active if legacy `is_active` is true.

## 4. Employee Managers

Comment: Run after all Employees are imported, because both the employee and manager must already exist.

Expected legacy columns:

```text
empnum, email, manager_empnum, manager_email, manager_name
```

Current handling:

- Employee is identified by `empnum`, employee code, email, or name.
- Manager is identified by manager empnum, manager email, or manager name.
- Numeric legacy empnum values are matched against the new `EMP...` employee code format.
- Self-manager assignments are rejected.
- Circular manager chains are rejected.
- Rows with no resolvable manager are skipped.

## 5. Leave Records

Comment: Run after Employees and Leave Types are available, because every leave row must connect to an employee and leave type.

Expected legacy columns:

```text
employeeId, employee_code, leavetype_name, leave_type,
appliedleavescount, from_date, to_date, from_time, to_time,
leavestatus, hr_status, reason
```

Current handling:

- `employeeId` or `employee_code` identifies the employee.
- `leavetype_name` is treated as `leave_type`.
- `appliedleavescount` is treated as `total_days`.
- `from_date` and `to_date` become leave start and end dates.
- `leavestatus` is treated as manager approval status.
- `hr_status` is treated as HR/final approval status.
- Approved leave requests are imported as approved.
- After importing leave records, run:

```bash
php artisan leave:recalculate-balances
```

This recalculates `used_days`, `pending_days`, and `remaining_days` from the imported leave request statuses.

## 6. Loan Records

Comment: Run after Employees and Loan Types are available. Missing loan types are created automatically, but reviewing loan types first is recommended.

Expected legacy columns:

```text
emailaddress, empnum, loanId, amount, loantype_name, from_date,
installmentnumber, financemanagerstatus, finance_manager_comment,
createddate, modifieddate, rep_manager_comment, reportingmanagerstatus,
reason, emi_amount, emi_date, emicreateddate
```

Current handling:

- Employee is resolved by `empnum`, employee code, or `emailaddress`.
- Numeric `empnum` values are matched against the new `EMP...` employee code format.
- `loanId` is used to group multiple installment rows into one loan record.
- `loantype_name` is treated as `loan_type`.
- `amount` is treated as the original/requested loan amount.
- `installmentnumber` is treated as the total number of installments where available.
- `reason` is saved as the loan purpose.
- `emi_amount` is saved as the monthly installment amount.
- `emi_date` is used as the installment due date.
- `financemanagerstatus` is used to decide finance approval/disbursed status.
- `reportingmanagerstatus` is used to decide manager approval status.
- Past approved EMI rows are marked as paid.
- Loan totals are refreshed after installment import: total paid, balance remaining, paid installment count, and skipped installment count.
- Missing loan types are created automatically.
- The same CSV can contain both loan summary data and installment rows.

## Recommended Post-Migration Commands

Some older repair commands are now covered by the Data Migration import itself, while others are still manual post-import commands.

| Command | Automatically handled by Data Migration? | Notes |
| --- | --- | --- |
| `php artisan leave:recalculate-balances` | No | Run manually after importing leave records. It recalculates used, pending, and remaining leave balances from imported leave requests. |
| `php artisan leave:backfill-contract-years` | No | Run manually when you need to create approved yearly contracts and annual leave allocations from employee joining dates. |
| `php artisan legacy:prefix-employee-codes` | Mostly yes for new imports | Employee import now stores numeric `empnum` as `EMP...`. Run this command only to repair employees imported before the prefix change. |
| `php artisan legacy:repair-employee-units path/to/userdetails.csv` | Mostly yes for new imports | Employee import now resolves units from `unitid`, `unit_name`, `branch_name`, or `businessunit_name`, and treats `Riyadh` as `Head Office`. Run this only to repair employees imported before that fix. |
| `php artisan legacy:migrate-managers path/to/usermanagerdetails.csv` | Yes, if using the `Employee Managers` migration scope | The Data Migration page can now import manager mappings using the `employee_managers` scope. The command is still useful for one-off repair outside the UI. |

Run these manually only when needed:

```bash
php artisan leave:recalculate-balances
php artisan leave:backfill-contract-years
php artisan legacy:prefix-employee-codes
php artisan legacy:repair-employee-units path/to/userdetails.csv
php artisan legacy:migrate-managers path/to/usermanagerdetails.csv
```

## Recommended Migration Flow

```text
1. Backup database.
2. Review/create Units in Admin.
3. Dry run Departments. Confirm deptname maps to name and duplicate branches are merged.
4. Import Departments.
5. Dry run Job Positions. Confirm positionname maps to title.
6. Import Job Positions.
7. Dry run Employees. Confirm empnum becomes EMP..., Riyadh becomes Head Office, and department/unit/designation mapping is correct.
8. Import Employees.
9. Dry run Employee Managers. Confirm manager mapping by empnum/email.
10. Import Employee Managers.
11. Dry run Leave Records. Confirm leave type, applied leave count, dates, manager status, and HR status.
12. Import Leave Records.
13. Run leave:recalculate-balances.
14. Dry run Loan Records. Confirm loan grouping by loanId and installment rows by emi_date.
15. Import Loan Records.
16. Verify employee profile, leave balance, contracts, loan requests, and payroll related pages.
```

## Notes

- The Data Migration screen supports CSV and Excel upload.
- For Excel uploads, sheets can be grouped by module name.
- For CSV uploads, choose the correct scope before uploading.
- The `All` scope uses the fixed backend order shown above.
- Always review the migration summary: processed, success, skipped, failed, and error details.
