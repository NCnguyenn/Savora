(function attachCatalog(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraCatalog = api;
}(typeof window === 'undefined' ? globalThis : window, function createCatalog(root) {
  'use strict';

  const products = {};
  const restaurants = {};
  const categories = [];
  const placeholderImage = 'assets/images/food-placeholder.svg';
  const placeholderLogo = 'assets/images/brands/restaurant-placeholder.svg';
  const imageFor = product => product && /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(String(product.image || ''))
    ? product.image
    : placeholderImage;
  const logoFor = restaurant => restaurant && /^assets\/images\/brands\/[a-z0-9][a-z0-9-]*\.svg$/.test(String(restaurant.logoPath || ''))
    ? restaurant.logoPath
    : placeholderLogo;
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const price = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const list = value => Array.isArray(value) ? value.map(entry => text(entry)).filter(Boolean) : [];
  const prepTime = value => Number.isFinite(Number(value)) && Number(value) > 0 ? `${Math.round(Number(value))} min` : 'Prepared to order';
  const categoryId = value => text(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'menu';

  function itemFromRecord(record) {
    const source = record && typeof record === 'object' ? record : {};
    const groups = Array.isArray(source.optionGroups) ? source.optionGroups : [];
    const choices = group => group && Array.isArray(group.optionChoices) ? group.optionChoices : [];
    const portions = choices(groups[0]).map(choice => ({ id: String(choice.publicId), label: text(choice.name), price: price(choice.priceDelta) }));
    const addOns = groups.slice(1).flatMap(choices).map(choice => ({ id: String(choice.publicId), productId: String(source.publicId), label: text(choice.name), price: price(choice.priceDelta) }));
    return {
      id: String(source.publicId), restaurant: text(source.restaurant && source.restaurant.name), restaurantId: String(source.restaurant && source.restaurant.id || ''),
      restaurantPublicId: text(source.restaurant && (source.restaurant.publicId || source.restaurant.public_id)),
      restaurantName: text(source.restaurant && source.restaurant.name), name: text(source.name), price: price(source.basePrice), available: source.available !== false,
      cuisine: text(source.restaurant && source.restaurant.cuisine),
      categories: [categoryId(source.category || (source.restaurant && source.restaurant.cuisine))],
      category: categoryId(source.category || (source.restaurant && source.restaurant.cuisine)),
      categoryLabel: text(source.category || (source.restaurant && source.restaurant.cuisine) || 'Menu'),
      itemType: source.itemType === 'drink' ? 'drink' : 'food',
      sortOrder: Number.isFinite(Number(source.sortOrder)) ? Number(source.sortOrder) : 0,
      image: text(source.imagePath), description: text(source.description), prepTime: prepTime(source.prepTimeMinutes),
      prepTimeMinutes: Number.isFinite(Number(source.prepTimeMinutes)) ? Number(source.prepTimeMinutes) : null,
      calories: Number.isFinite(Number(source.calories)) ? Number(source.calories) : 0,
      dietaryTags: list(source.dietaryTags), allergens: list(source.allergens), ingredients: list(source.ingredients),
      portions, addOns, version: Number(source.version || 0)
    };
  }

  function replaceRecords(records) {
    Object.keys(products).forEach(key => delete products[key]);
    Object.keys(restaurants).forEach(key => delete restaurants[key]);
    categories.splice(0, categories.length);
    (Array.isArray(records) ? records : []).map(itemFromRecord).filter(item => item.id).forEach(item => {
      products[item.id] = item;
      const name = item.restaurant || 'Restaurant';
      const source = (Array.isArray(records) ? records : []).find(record => String(record && record.publicId || '') === item.id) || {};
      const restaurantSource = source.restaurant && typeof source.restaurant === 'object' ? source.restaurant : {};
      const existing = restaurants[name] || {
        publicId: item.restaurantPublicId || String(item.restaurantId), name, cuisine: item.cuisine,
        slogan: text(restaurantSource.slogan), logoPath: text(restaurantSource.logoPath || restaurantSource.logo_path),
        rating: Number.isFinite(Number(restaurantSource.rating)) ? String(Number(restaurantSource.rating)) : 'No rating',
        description: text(restaurantSource.description), heroImage: text(restaurantSource.heroImage), image: text(restaurantSource.heroImage),
        address: text(restaurantSource.address), city: text(restaurantSource.city), phone: text(restaurantSource.phone),
        prepTime: item.prepTime, productIds: []
      };
      if (!existing.productIds.includes(item.id)) existing.productIds.push(item.id);
      restaurants[name] = existing;
      if (!categories.some(category => category.id === item.category)) categories.push({ id: item.category, label: item.categoryLabel });
    });
    return api;
  }

  async function hydrate(query = '') {
    const suffix = query ? `?${query}` : '';
    const records = await root.SavoraApi.get(`api/catalog.php${suffix}`);
    return replaceRecords(records);
  }

  const api = { products, restaurants, categories, placeholderImage, placeholderLogo, imageFor, logoFor, itemFromRecord, replaceRecords, hydrate };
  return api;
}));
