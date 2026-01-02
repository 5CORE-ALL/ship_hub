-- ============================================
-- Find Missing Labels from Recent Bulk Purchase
-- ============================================
-- Run these queries on your live database to identify the 53 missing labels
-- ============================================

-- Query 1: Find the BulkShippingHistory record from ~30 minutes ago
-- Look for the record where you purchased 80 labels but only got 27
SELECT 
    id,
    created_at,
    processed as total_orders,
    success as successful_labels,
    failed as failed_labels,
    (processed - success - failed) as missing_count,
    status,
    user_id,
    JSON_LENGTH(order_ids) as total_order_ids,
    JSON_LENGTH(COALESCE(success_order_ids, '[]')) as success_order_ids_count,
    JSON_LENGTH(COALESCE(failed_order_ids, '[]')) as failed_order_ids_count
FROM bulk_shipping_histories
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
ORDER BY created_at DESC
LIMIT 10;

-- Query 2: Find orders from the history that don't have active shipments
-- Replace HISTORY_ID with the ID from Query 1
SET @history_id = NULL; -- Replace with actual history ID from Query 1

SELECT 
    o.id as order_id,
    o.order_number,
    o.marketplace,
    o.label_status,
    o.fulfillment_status,
    o.queue_started_at,
    COUNT(s.id) as total_shipments,
    MAX(CASE 
        WHEN s.label_status = 'active' AND s.void_status = 'active' 
        THEN 1 ELSE 0 
    END) as has_active_shipment,
    GROUP_CONCAT(DISTINCT s.label_status) as shipment_statuses
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id
WHERE o.id IN (
    SELECT JSON_UNQUOTE(JSON_EXTRACT(order_ids, CONCAT('$[', idx, ']')))
    FROM bulk_shipping_histories,
    JSON_TABLE(
        JSON_ARRAY(0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99),
        '$[*]' COLUMNS (idx INT PATH '$')
    ) AS t
    WHERE id = @history_id
    AND JSON_UNQUOTE(JSON_EXTRACT(order_ids, CONCAT('$[', idx, ']'))) IS NOT NULL
)
GROUP BY o.id, o.order_number, o.marketplace, o.label_status, o.fulfillment_status, o.queue_started_at
HAVING has_active_shipment = 0
ORDER BY o.queue_started_at DESC;

-- Query 3: Simpler version - Find orders processed recently without active shipments
SELECT 
    o.id as order_id,
    o.order_number,
    o.marketplace,
    o.label_status,
    o.fulfillment_status,
    o.queue_started_at,
    o.queue,
    COUNT(s.id) as total_shipments,
    MAX(CASE 
        WHEN s.label_status = 'active' AND s.void_status = 'active' 
        THEN 1 ELSE 0 
    END) as has_active_shipment
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    AND o.queue = 0  -- Processing completed
GROUP BY o.id, o.order_number, o.marketplace, o.label_status, o.fulfillment_status, o.queue_started_at, o.queue
HAVING has_active_shipment = 0
ORDER BY o.queue_started_at DESC;

-- Query 4: Get order IDs for the recovery command
-- This will output a comma-separated list you can use
SELECT GROUP_CONCAT(o.id ORDER BY o.id) as missing_order_ids
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id 
    AND s.label_status = 'active' 
    AND s.void_status = 'active'
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    AND o.queue = 0
    AND s.id IS NULL
GROUP BY NULL;

-- Query 5: Check specific order IDs from the history
-- Replace HISTORY_ID and adjust the order_ids array extraction as needed
-- This is a simplified version that works if you know the order IDs
SELECT 
    o.id,
    o.order_number,
    o.label_status,
    o.fulfillment_status,
    s.id as shipment_id,
    s.label_status as shipment_label_status,
    s.void_status as shipment_void_status
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id 
    AND s.label_status = 'active' 
    AND s.void_status = 'active'
WHERE o.id IN (
    -- Replace these with actual order IDs from the history
    1, 2, 3, 4, 5  -- Example: replace with actual order IDs
)
ORDER BY o.id;

-- Query 6: Summary of the issue
SELECT 
    'Total in History' as metric,
    COUNT(*) as count
FROM orders o
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
UNION ALL
SELECT 
    'With Active Shipments' as metric,
    COUNT(DISTINCT o.id) as count
FROM orders o
INNER JOIN shipments s ON s.order_id = o.id
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    AND s.label_status = 'active'
    AND s.void_status = 'active'
UNION ALL
SELECT 
    'Missing Labels' as metric,
    COUNT(DISTINCT o.id) as count
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id 
    AND s.label_status = 'active' 
    AND s.void_status = 'active'
WHERE o.queue_started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    AND o.queue = 0
    AND s.id IS NULL;

