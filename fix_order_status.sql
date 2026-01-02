-- Direct SQL fix for orders with active shipments but incorrect order_status
-- Run this on live server database

UPDATE orders o
INNER JOIN shipments s ON s.order_id = o.id AND s.label_status = 'active'
SET 
    o.order_status = 'Shipped',
    o.printing_status = 1,
    o.label_status = 'purchased',
    o.label_source = 'api',
    o.fulfillment_status = 'shipped',
    o.updated_at = NOW()
WHERE 
    (
        -- Orders with printing_status = 1 but order_status != 'shipped'
        (o.printing_status = 1 AND LOWER(o.order_status) != 'shipped')
        OR
        -- Orders with printing_status = 0 but order_status != 'shipped'
        (o.printing_status = 0 AND LOWER(o.order_status) != 'shipped')
    );

-- Check the specific order
SELECT 
    o.id,
    o.order_number,
    o.order_status,
    o.printing_status,
    o.label_status,
    s.label_status as shipment_label_status
FROM orders o
LEFT JOIN shipments s ON s.order_id = o.id AND s.label_status = 'active'
WHERE o.order_number = '02-14054-81298';

