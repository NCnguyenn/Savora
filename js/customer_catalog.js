(function attachCatalog(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraCatalog = api;
}(typeof window === 'undefined' ? globalThis : window, function createCatalog(root) {
  'use strict';

  const products = {};
  const restaurants = {};
  const placeholderImage = 'assets/images/food-placeholder.svg';
  const imageFor = product => product && /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(String(product.image || ''))
    ? product.image
    : placeholderImage;
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const price = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;

  function itemFromRecord(record) {
    const source = record && typeof record === 'object' ? record : {};
    const groups = Array.isArray(source.optionGroups) ? source.optionGroups : [];
    const choices = group => group && Array.isArray(group.optionChoices) ? group.optionChoices : [];
    const portions = choices(groups[0]).map(choice => ({ id: String(choice.publicId), label: text(choice.name), price: price(choice.priceDelta) }));
    const addOns = groups.slice(1).flatMap(choices).map(choice => ({ id: String(choice.publicId), productId: String(source.publicId), label: text(choice.name), price: price(choice.priceDelta) }));
    return {
      id: String(source.publicId), restaurant: text(source.restaurant && source.restaurant.name), restaurantId: String(source.restaurant && source.restaurant.id || ''),
      restaurantName: text(source.restaurant && source.restaurant.name), name: text(source.name), price: price(source.basePrice), available: source.available !== false,
      categories: [text(source.restaurant && source.restaurant.cuisine).toLowerCase() || 'menu'], category: text(source.restaurant && source.restaurant.cuisine).toLowerCase() || 'menu',
      image: '', description: '', prepTime: 'Prepared to order', calories: 0, dietaryTags: [], allergens: [], ingredients: [],
      portions: portions.length ? portions : [{ id: 'regular', label: 'Regular', price: 0 }], addOns, version: Number(source.version || 0)
    };
  }

  function replaceRecords(records) {
    Object.keys(products).forEach(key => delete products[key]);
    Object.keys(restaurants).forEach(key => delete restaurants[key]);
    (Array.isArray(records) ? records : []).map(itemFromRecord).filter(item => item.id).forEach(item => {
      products[item.id] = item;
      const name = item.restaurant || 'Restaurant';
      const existing = restaurants[name] || { name, cuisine: item.category, rating: '—', prepTime: item.prepTime, productIds: [] };
      if (!existing.productIds.includes(item.id)) existing.productIds.push(item.id);
      restaurants[name] = existing;
    });
    return api;
  }

  async function hydrate(query = '') {
    const suffix = query ? `?${query}` : '';
    const records = await root.SavoraApi.get(`api/catalog.php${suffix}`);
    return replaceRecords(records);
  }

  const api = { products, restaurants, placeholderImage, imageFor, itemFromRecord, replaceRecords, hydrate };
  return api;
}));
