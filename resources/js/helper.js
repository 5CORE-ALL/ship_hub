function renderMarketplaceIcon(data) {
    if (!data) return '';

    const value = data.toString().trim().toLowerCase();
    const name = value.charAt(0).toUpperCase() + value.slice(1);

    switch (value) {
        case 'amazon':
            return '<span><i class="fab fa-amazon me-2 text-primary fs-5"></i>Amazon</span>';

        case 'reverb':
            return '<span><i class="fas fa-guitar me-2 text-primary fs-5"></i>' + name + '</span>';

        case 'walmart':
            return '<span><i class="fas fa-store me-2 text-warning fs-5"></i>' + name + '</span>';

        case 'tiktok':
            return '<span><i class="fab fa-tiktok me-2" style="color:#ee1d52;"></i>' + name + '</span>';

        case 'temu':
            return '<span><i class="fas fa-shopping-bag me-2" style="color:#ff6200;"></i>' + name + '</span>';

        case 'shopify':
            return '<span><i class="fab fa-shopify me-2 text-success fs-5"></i>' + name + '</span>';

        case 'best buy usa':
            return '<span><i class="fas fa-bolt me-2" style="color:#0046be;"></i>' + name + '</span>';

        default:
            if (value.startsWith('ebay')) {
                return '<span><i class="fab fa-ebay me-2 text-danger fs-5"></i>' + name + '</span>';
            }
            return '<span><i class="fas fa-globe me-2 text-secondary fs-5"></i>' + name + '</span>';
    }
}