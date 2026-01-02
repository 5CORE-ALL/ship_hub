# Recover Missing Labels - User Guide

## Overview
This guide helps you recover the 53 missing labels from your previous bulk purchase.

## Step 1: Identify the Missing Orders

### Option A: Find the BulkShippingHistory Record

Run this SQL query on your live database to find the recent bulk purchase:

```sql
SELECT 
    id,
    created_at,
    processed,
    success,
    failed,
    status,
    user_id,
    JSON_LENGTH(order_ids) as total_orders,
    JSON_LENGTH(COALESCE(success_order_ids, '[]')) as success_count,
    JSON_LENGTH(COALESCE(failed_order_ids, '[]')) as failed_count
FROM bulk_shipping_histories
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
ORDER BY created_at DESC
LIMIT 10;
```

Look for the record where:
- `processed` = 80 (or close to it)
- `success` = 27 (or close to it)
- `failed` = 53 (or the difference)

Note the `id` of this record.

### Option B: Find Orders Without Active Shipments

Run this SQL to find orders that were processed but don't have active shipments:

```sql
-- Find orders processed in the last 2 hours without active shipments
SELECT 
    o.id,
    o.order_number,
    o.queue_started_at,
    o.label_status,
    o.fulfillment_status,
    COUNT(s.id) as shipment_count,
    MAX(CASE WHEN s.label_status = 'active' AND s.void_status = 'active' THEN 1 ELSE 0 END) as has_active_shipment
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    AND o.queue = 0  -- Processing completed
GROUP BY o.id, o.order_number, o.queue_started_at, o.label_status, o.fulfillment_status
HAVING has_active_shipment = 0
ORDER BY o.queue_started_at DESC;
```

## Step 2: Use the Recovery Command

### Basic Usage (Recover from Last Hour)

```bash
php artisan labels:recover-missing
```

This will:
- Look for bulk shipping histories from the last hour
- Find orders that don't have active shipments
- Retry creating labels for those orders

### Recover from Specific History ID

If you found the BulkShippingHistory ID from Step 1:

```bash
php artisan labels:recover-missing --history-id=123
```

Replace `123` with the actual history ID.

### Dry Run (See What Would Be Recovered)

Before actually processing, you can see what would be recovered:

```bash
php artisan labels:recover-missing --history-id=123 --dry-run
```

or

```bash
php artisan labels:recover-missing --hours=2 --dry-run
```

### Force Retry (Even if Active Shipment Exists)

If you want to retry even orders that have active shipments:

```bash
php artisan labels:recover-missing --history-id=123 --force
```

⚠️ **Warning**: Use `--force` carefully as it may create duplicate shipments.

### Custom Time Range

To look back more than 1 hour:

```bash
php artisan labels:recover-missing --hours=3
```

## Step 3: Verify Recovery

After running the recovery command, verify the results:

```sql
-- Check the updated history
SELECT 
    id,
    processed,
    success,
    failed,
    status,
    JSON_LENGTH(COALESCE(success_order_ids, '[]')) as actual_success_count
FROM bulk_shipping_histories
WHERE id = YOUR_HISTORY_ID;
```

```sql
-- Verify shipments were created
SELECT 
    COUNT(*) as active_shipments
FROM shipments
WHERE order_id IN (
    SELECT id FROM orders 
    WHERE queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
)
AND label_status = 'active'
AND void_status = 'active';
```

## Example Workflow

1. **Find the history record:**
   ```bash
   # Run SQL query from Step 1, Option A
   # Note the history ID (e.g., 456)
   ```

2. **Dry run to see what will be recovered:**
   ```bash
   php artisan labels:recover-missing --history-id=456 --dry-run
   ```

3. **Review the output** - it will show:
   - How many orders are missing
   - Which orders are missing
   - Their current status

4. **Run the actual recovery:**
   ```bash
   php artisan labels:recover-missing --history-id=456
   ```

5. **Verify the results:**
   - Check the command output for success/failure counts
   - Run the verification SQL queries
   - Check the BulkShippingHistory record was updated

## Troubleshooting

### No Missing Orders Found
- The orders may have been recovered already
- Check if orders have active shipments: `SELECT * FROM shipments WHERE order_id IN (...)`
- Try increasing the time range: `--hours=3`

### Some Orders Still Fail
- Check the Laravel logs: `storage/logs/laravel.log`
- Look for specific error messages for those orders
- The orders may have data issues (missing rates, invalid addresses, etc.)

### Command Not Found
- Make sure you're in the project root directory
- Run `php artisan list` to see all available commands
- The command should appear as `labels:recover-missing`

## Important Notes

1. **The recovery command uses the same label creation logic** - so it will respect all business rules (active shipments, cheapest rates, etc.)

2. **Orders are locked during processing** - prevents duplicate processing

3. **History is updated** - if you provide `--history-id`, the original BulkShippingHistory record will be updated with the new counts

4. **Logs are created** - all recovery attempts are logged in `storage/logs/laravel.log`

5. **Safe to run multiple times** - the command checks for active shipments before processing

## Quick Reference

```bash
# See all options
php artisan labels:recover-missing --help

# Dry run for last hour
php artisan labels:recover-missing --dry-run

# Recover from specific history
php artisan labels:recover-missing --history-id=123

# Recover from last 3 hours
php artisan labels:recover-missing --hours=3

# Force retry (use with caution)
php artisan labels:recover-missing --history-id=123 --force
```

