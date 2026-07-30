(function attachState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraState = api;
}(typeof window === 'undefined' ? null : window, function createState() {
  const KEY = 'savora_customer_state_v2';
  const DELIVERY_FEE = 2;
  const LEGACY_RESTAURANT_ID = 'savora-kitchen';
  const LEGACY_RESTAURANT_NAME = 'Savora Kitchen';
  const profileKeys = ['fullName', 'email', 'address', 'phone'];
  const orderStatuses = ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'on_the_way', 'completed', 'cancelled'];
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const deliveryNote = value => text(value).trim().slice(0, 120);
  const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const copy = value => JSON.parse(JSON.stringify(value));
  const restaurantIdentity = (source, fallback = {}) => {
    const id = text(source && source.restaurantId).trim() || text(fallback.id).trim() || LEGACY_RESTAURANT_ID;
    const name = text(source && (source.restaurantName || source.restaurant)).trim()
      || text(fallback.name).trim()
      || (id === LEGACY_RESTAURANT_ID ? LEGACY_RESTAURANT_NAME : id);
    return { id, name };
  };
  const restaurantAccepting = (restaurantConfig, restaurantId) => {
    const source = restaurantConfig && typeof restaurantConfig === 'object' && !Array.isArray(restaurantConfig) ? restaurantConfig : {};
    const profile = source.profile && typeof source.profile === 'object' ? source.profile : {};
    if (text(profile.id).trim() !== text(restaurantId).trim()) return true;
    const operations = source.operations && typeof source.operations === 'object' ? source.operations : {};
    return operations.acceptingOrders !== false;
  };
  const defaultState = () => ({
    version: 2,
    cart: [],
    favorites: { restaurants: [], products: [] },
    profile: {},
    wallet: { balance: 0, transactions: [] },
    orders: []
  });
  const lineTotal = line => line.unitPrice * line.quantity;
  const normalizeOption = option => ({
    id: text(option && option.id),
    label: text(option && option.label),
    price: Math.max(0, number(option && option.price))
  });
  const normalizeCartLine = (line, index = 0) => {
    const id = text(line && line.id);
    const restaurant = restaurantIdentity(line);
    return {
      lineId: text(line && line.lineId) || (id ? `legacy-${id}-${index}` : ''),
      id,
      restaurantId: restaurant.id,
      restaurantName: restaurant.name,
      name: text(line && line.name),
      image: text(line && line.image),
      unitPrice: Math.max(0, number(line && line.unitPrice)),
      quantity: Math.max(1, Math.floor(number(line && line.quantity))),
      options: Array.isArray(line && line.options) ? line.options.map(normalizeOption) : [],
      note: text(line && line.note)
    };
  };
  const normalizeOwnedLines = (rawLines, owner) => {
    const lines = Array.isArray(rawLines) ? rawLines.map(normalizeCartLine).filter(line => line.lineId && line.id) : [];
    const identity = restaurantIdentity(owner || lines[0], lines[0] && { id: lines[0].restaurantId, name: lines[0].restaurantName });
    return {
      owner: identity,
      lines: lines.filter(line => line.restaurantId === identity.id).map(line => ({
        ...line, restaurantId: identity.id, restaurantName: identity.name
      }))
    };
  };
  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    state.profile = Object.fromEntries(profileKeys.map(key => [key, text(source.profile && source.profile[key])]));
    state.wallet.balance = Math.max(0, number(source.wallet && source.wallet.balance));
    state.wallet.transactions = Array.isArray(source.wallet && source.wallet.transactions)
      ? source.wallet.transactions.map(item => ({
        id: text(item && item.id),
        kind: item && item.kind === 'credit' ? 'credit' : 'debit',
        amount: Math.max(0, number(item && item.amount)),
        label: text(item && item.label),
        createdAt: text(item && item.createdAt)
      })).filter(item => item.id)
      : [];
    state.favorites.products = [...new Set(Array.isArray(source.favorites && source.favorites.products)
      ? source.favorites.products.map(String) : [])];
    state.favorites.restaurants = [...new Set(Array.isArray(source.favorites && source.favorites.restaurants)
      ? source.favorites.restaurants.map(String) : [])];
    state.cart = normalizeOwnedLines(source.cart).lines;
    state.orders = Array.isArray(source.orders) ? source.orders.map(order => {
      const ownedItems = normalizeOwnedLines(order && order.items, order);
      const items = ownedItems.lines;
      const status = orderStatuses.includes(order && order.status) ? order.status : 'completed';
      const history = Array.isArray(order && order.statusHistory) ? order.statusHistory.map(entry => ({
        status: orderStatuses.includes(entry && entry.status) ? entry.status : '',
        createdAt: text(entry && entry.createdAt),
        actor: text(entry && entry.actor)
      })).filter(entry => entry.status) : [];
      return {
      id: text(order && order.id),
      status,
      statusHistory: history,
      restaurantId: ownedItems.owner.id,
      restaurantName: ownedItems.owner.name,
      address: text(order && order.address),
      deliveryNote: deliveryNote(order && order.deliveryNote),
      paymentMethod: order && order.paymentMethod === 'wallet' ? 'wallet' : 'cash',
      promoCode: text(order && order.promoCode),
      items,
      subtotal: Math.max(0, number(order && order.subtotal)),
      deliveryFee: Math.max(0, number(order && order.deliveryFee)),
      total: Math.max(0, number(order && order.total)),
      createdAt: text(order && order.createdAt)
    }; }).filter(order => order.id) : [];
    return state;
  }
  function load() {
    if (typeof localStorage === 'undefined') return defaultState();
    try {
      return normalize(JSON.parse(localStorage.getItem(KEY) || 'null'));
    } catch (error) {
      return defaultState();
    }
  }
  function persist(state) {
    const next = normalize(state);
    if (typeof localStorage !== 'undefined') localStorage.setItem(KEY, JSON.stringify(next));
    return next;
  }
  function addCartLine(state, product, quantity, options = [], note = '') {
    const next = normalize(state);
    const qty = Math.max(1, Math.floor(number(quantity)));
    const productId = product && product.id != null ? text(String(product.id)) : '';
    if (!productId) throw new Error('Product id is required');
    const restaurant = restaurantIdentity(product);
    const restaurantId = restaurant.id;
    const restaurantName = restaurant.name;
    const cartRestaurantId = next.cart.find(line => line.restaurantId)?.restaurantId || '';
    if (restaurantId && cartRestaurantId && restaurantId !== cartRestaurantId) throw new Error('A cart can contain items from one restaurant only');
    const normalizedOptions = Array.isArray(options) ? options.map(normalizeOption) : [];
    const unitPrice = Math.max(0, number(product && product.price))
      + normalizedOptions.reduce((sum, option) => sum + option.price, 0);
    const normalizedNote = text(note);
    const key = JSON.stringify([productId, normalizedOptions, normalizedNote]);
    const existing = next.cart.find(line => JSON.stringify([line.id, line.options, line.note]) === key);
    if (existing) existing.quantity += qty;
    else next.cart.push({
      lineId: `${productId}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      id: productId,
      restaurantId,
      restaurantName,
      name: text(product && product.name),
      image: text(product && (product.image || product.img)),
      unitPrice,
      quantity: qty,
      options: normalizedOptions,
      note: normalizedNote
    });
    return next;
  }
  function removeCartLine(state, lineId) {
    const next = normalize(state);
    next.cart = next.cart.filter(line => line.lineId !== text(lineId));
    return next;
  }
  function updateCartQuantity(state, lineId, delta) {
    const next = normalize(state);
    const line = next.cart.find(item => item.lineId === text(lineId));
    if (!line) return next;
    line.quantity += Math.trunc(number(delta));
    return line.quantity > 0 ? next : removeCartLine(next, lineId);
  }
  function setProfile(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' ? patch : {};
    for (const key of profileKeys) if (Object.hasOwn(source, key)) next.profile[key] = text(source[key]);
    return next;
  }
  function topUpWallet(state, amount) {
    const next = normalize(state);
    const credit = Math.max(0, number(amount));
    if (!credit) throw new Error('Top-up amount must be positive');
    next.wallet.balance += credit;
    next.wallet.transactions.unshift({
      id: `topup-${Date.now()}`,
      kind: 'credit',
      amount: credit,
      label: 'Local demo top-up',
      createdAt: new Date().toISOString()
    });
    return next;
  }
  function toggleFavorite(state, kind, id) {
    const next = normalize(state);
    if (!['products', 'restaurants'].includes(kind)) throw new Error('Unsupported favorite kind');
    const value = String(id);
    const list = next.favorites[kind];
    next.favorites[kind] = list.includes(value) ? list.filter(item => item !== value) : [...list, value];
    return next;
  }
  function placeDemoOrder(state, input, restaurantConfig) {
    const next = normalize(state);
    const source = input && typeof input === 'object' ? input : {};
    const address = text(source.address).trim();
    const normalizedDeliveryNote = deliveryNote(source.deliveryNote);
    if (!address) throw new Error('Delivery address is required');
    if (!next.cart.length) throw new Error('Cart is empty');
    if (!restaurantAccepting(restaurantConfig, next.cart[0].restaurantId)) throw new Error('This restaurant is not accepting orders right now.');
    const subtotal = next.cart.reduce((sum, line) => sum + lineTotal(line), 0);
    const total = subtotal + DELIVERY_FEE;
    const paymentMethod = source.paymentMethod === 'wallet' ? 'wallet' : 'cash';
    if (paymentMethod === 'wallet' && next.wallet.balance < total) throw new Error('Insufficient wallet balance');
    const createdAt = new Date().toISOString();
    const order = {
      id: `SVR-${Date.now()}`,
      status: 'pending',
      statusHistory: [{ status: 'pending', createdAt, actor: 'customer' }],
      restaurantId: next.cart[0].restaurantId || '',
      restaurantName: next.cart[0].restaurantName || '',
      address,
      deliveryNote: normalizedDeliveryNote,
      paymentMethod,
      promoCode: text(source.promoCode),
      items: copy(next.cart),
      subtotal,
      deliveryFee: DELIVERY_FEE,
      total,
      createdAt
    };
    if (paymentMethod === 'wallet') {
      next.wallet.balance -= total;
      next.wallet.transactions.unshift({
        id: `order-${order.id}`,
        kind: 'debit',
        amount: total,
        label: `Local demo order ${order.id}`,
        createdAt
      });
    }
    next.orders.unshift(order);
    next.cart = [];
    return { state: next, order };
  }
  function getActiveOrder(state) {
    return normalize(state).orders.find(order => ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'on_the_way'].includes(order.status)) || null;
  }
  return { KEY, DELIVERY_FEE, defaultState, normalize, load, persist, addCartLine, removeCartLine, updateCartQuantity, topUpWallet, setProfile, toggleFavorite, getActiveOrder, placeDemoOrder };
}));
