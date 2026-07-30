# Báo cáo xác minh Customer UI/UX — cập nhật review 2026-07-30

## Kết luận

PASS. Font Awesome, Leaflet library, bốn ảnh catalog và ba ảnh CSS background quan trọng đã được self-host; Google Fonts import đã được thay bằng system font stack. Visual sweep không còn được phép pass nếu glyph, ảnh món, discovery hero hoặc promo banner chưa tải. Favorites có heart độc lập ở Discover/Product; delivery note được normalize, lưu và render text-only; title mỗi Customer route do shared header map cung cấp. CARTO raster tiles vẫn là dependency ngoài không quan trọng và có degraded state nhìn thấy được. Toàn bộ mã cùng nguồn, luồng nghiệp vụ local-demo, kiểm tra bàn phím, ranh giới dữ liệu không tin cậy và 24 tổ hợp trang/kích thước đều đạt.

Phạm vi gồm tám trang Customer đã duyệt trong đặc tả và mockup: Discover, Product detail, Cart, Checkout, Orders, Favorites, Profile và Savora Pay. Việc kiểm tra dùng đăng nhập Customer thật của ứng dụng tại `http://localhost/Savora`, không thay thế hành vi trang bằng fixture HTML.

## Cổng kiểm tra tự động cuối cùng

Các lệnh dưới đây được chạy mới sau tất cả thay đổi:

```powershell
node --test tests\customer_state.test.js tests\customer_markup.test.js
node --check js\customer_catalog.js
node --check js\customer_state.js
node --check js\customer_ui.js
node --check tests\task7_browser_qa.mjs

$php = 'D:\Xampp\php\php.exe'
& $php -l components\customer_header.php
& $php -l components\customer_footer.php
& $php -l customer_dashboard.php
& $php -l product_detail.php
& $php -l customer_cart.php
& $php -l customer_checkout.php
& $php -l customer_history.php
& $php -l customer_favorites.php
& $php -l customer_profile.php
& $php -l customer_wallet.php

$env:SAVORA_CDP_PORT = '9235'
node tests\task7_browser_qa.mjs
```

Kết quả:

- Node test: 47/47 pass, 0 fail, 0 skipped, 0 todo.
- Node syntax: bốn tệp đều exit 0.
- PHP lint: 10/10 tệp báo `No syntax errors detected`.
- Browser QA trên Chrome 150.0.7871.187 với profile mới `C:\tmp\savora-task7-final-review-005`, CDP port 9235: `status: PASS`, 0 runtime exception, 0 lỗi request cùng origin.
- Visual QA: 24/24 tổ hợp trang/kích thước đúng pathname và document title expected, có `<main>` và heading hiển thị, không mojibake, không tràn chiều ngang tài liệu, font status `loaded`, glyph hợp lệ, mọi ảnh critical có kích thước tự nhiên lớn hơn 0 và hai CSS background critical trên Discover cùng origin/tải thành công ở cả ba breakpoint.

## Bằng chứng TDD cho các lỗi hồi quy/contract

### 1. Tên truy cập của nút xóa trong Cart

RED: hợp đồng markup mới yêu cầu tên nút xóa gắn với món cụ thể; nguồn cũ chỉ có nội dung chung `Remove`, nên nhóm kiểm tra mục tiêu có 4 pass và 1 fail.

GREEN: `customer_cart.php` tạo `aria-label` theo `Remove ${name}`. Browser xác nhận tên thực tế là `Remove Supreme Pepperoni Pizza 12"`.

### 2. Focus của menu di động

RED: browser mở menu ở 320px nhưng hết thời gian chờ focus đi vào menu; hợp đồng nguồn cho focus-on-open/Escape-return cũng fail.

GREEN: helper menu dùng một đường cập nhật trạng thái duy nhất, chuyển focus tới liên kết đầu tiên sau khi mở và trả focus về nút toggle khi nhấn Escape. Hợp đồng nguồn và browser đều pass.

### 3. Ghi chú tùy chỉnh dài làm Cart rộng hơn viewport

RED: sweep 320px ghi nhận `scrollWidth 342 > 320`; phần tử gây lỗi là dòng ghi chú dài trong `.line-customizations li`. Hợp đồng CSS mới fail trước khi sửa.

GREEN: thêm `overflow-wrap: anywhere`; sweep mới ghi nhận Cart `scrollWidth 320`, không cắt mất nút hoặc nội dung.

Một ảnh Favorites ban đầu bắt đúng giữa animation toast trượt vào 300ms nên trông như bị cắt. Đây không phải trạng thái ổn định. QA hiện chờ animation kết thúc rồi kiểm tra hình học: cả hai toast có `left: 16`, `right: 289` trong viewport 320px, sau đó mới chụp ảnh.

### 4. Critical visual assets bị phụ thuộc CDN

RED: source gate mới fail vì header/footer còn tham chiếu cdnjs/unpkg và chưa có local asset. Sau khi menu tests GREEN, browser suite tiếp tục tới visual sweep rồi fail đúng tại `Timed out: discover at 1440px local catalog images`; catalog khi đó không có ảnh local nào để chứng minh đã tải.

GREEN: Font Awesome Free 6.4.0, Leaflet 1.9.4 và bốn JPEG catalog được vendor vào `assets/`; `SavoraCatalog.imageFor` chỉ cho phép ảnh catalog local và trả `assets/images/food-placeholder.svg` cho unknown/empty/external URL. Browser mới xác minh placeholder tải được ở 800×560, 24/24 route captures có Font Awesome pseudo-glyph hợp lệ, và mọi ảnh critical `complete` với `naturalWidth/naturalHeight > 0`.

### 5. Menu mobile thiếu outside-click contract

RED: browser mở menu ở 320px, click ngoài menu và ghi nhận `open: "true"`, `expanded: "true"`, `focusReturned: false`.

GREEN: document click handler chỉ đóng menu khi viewport tối đa 768px và target nằm ngoài nav/toggle; nếu focus đang ở liên kết sắp bị ẩn thì trả focus về toggle. Browser tiếp tục xác nhận click liên kết Favorites vừa đóng vừa điều hướng, trong khi desktop 1440px vẫn hiển thị nav dạng flex, ẩn toggle và Escape không đổi state/focus.

### 6. Map tile ngoài mạng không có trạng thái rõ

RED: source contract fail vì map không có `tileerror`, status hoặc thông báo fallback.

GREEN: Leaflet library được self-host; CARTO tile layer đặt map vào `degraded` khi tile lỗi và hiện `Map tiles unavailable — delivery markers remain visible.`. Browser chủ động block CARTO, xác nhận message nhìn thấy, accessible label được cập nhật và chụp `map-fallback-1440.png`.

### 7. CSS visual assets vẫn phụ thuộc mạng ngoài

RED: source contract mới chặn `http(s)` trong hai stylesheet Customer và fail ngay tại Google Fonts import cùng các URL Unsplash trong `css/style.css`/`css/customer_style.css`. Browser gate cũ chỉ kiểm tra `<img>` và icon font nên chưa thể chứng minh hero/promo background đã tải.

GREEN: font chuyển sang system stack; ba JPEG background được lưu trong `assets/images/backgrounds/` và mọi CSS URL chuyển sang đường dẫn local. Discover hero/promo có marker semantic; browser đọc computed `backgroundImage`, yêu cầu URL cùng origin, preload từng ảnh và fail nếu không có `naturalWidth/naturalHeight > 0`. Kết quả ở 1440/768/320 đều có 2/2 background: `discovery-pasta.jpg` 1800×1201 và `produce-promo.jpg` 1000×1512; gradient fallback được ghi rõ trong evidence.

### 8. Không thể tạo favorite từ trạng thái sạch

RED: source contract không tìm thấy `discovery-card-shell`/heart control; browser scenario mới dừng tại `Timed out: Discover restaurant favorite control`.

GREEN: Discover bọc link/button card và heart bằng article shell, nên các control không lồng nhau. Product có heart riêng. Browser từ favorites rỗng thêm Pizza Hut ở Discover và pizza ở Product, reload để xác nhận persistence/hiển thị tại Favorites, sau đó xóa cả hai. Mỗi bước xác nhận `aria-pressed`, accessible label và toast.

### 9. Delivery note bị Checkout bỏ qua

RED: state test nhận `undefined` thay vì note đã trim/cap; browser dừng `checkout discarded delivery note`.

GREEN: `placeDemoOrder` normalize note bằng trim và cap 120, giữ tương thích với call cũ. Checkout truyền field; Orders, active history và tracking đều render bằng text node. Payload `<img src=x onerror=window.__task7Xss=1>` còn literal trong UI/state, không tạo image node và không chạy XSS.

### 10. Document title dùng một giá trị chung

RED: source title-map contract thiếu `Discover | Savora`; browser dừng ở `discover document title mismatch` khi nhận title chung cũ.

GREEN: header map có tám route Customer; Product vẫn cập nhật title động theo dish sau khi data render. Visual sweep so title chính xác tại 1440, 768 và 320 cho toàn bộ tám route.

## Luồng nghiệp vụ đã xác minh trong trình duyệt

### Discover và Product

- Trạng thái sạch không dựng đơn hàng hoặc bản đồ giả; vùng tracking hiển thị `Nothing on the way yet`.
- Tìm `Pizza Hut` cùng lúc trả về 1 món và 1 nhà hàng.
- Kết hợp từ khóa với category Burger tạo đúng hai empty state độc lập.
- Product id 2 chọn phần lớn `+$4.00`, Extra mozzarella `+$1.75`, số lượng 2; tổng UI chính xác `$39.48`.
- State giữ số lượng 2, đơn giá cấu hình 19.74, đúng hai option và ghi chú.
- Từ favorites rỗng, Discover thêm restaurant và Product thêm dish; reload vẫn hiện cả hai tại Favorites. Remove của từng kind độc lập, có label/pressed state/toast đúng và không có nested interactive control.

### Cart, Checkout và Orders

- Full Cart dùng dữ liệu state chuẩn hóa; nút xóa có tên theo món; ảnh lấy từ catalog tin cậy.
- Cart drawer nhận focus, giữ Tab/Shift+Tab bên trong, đóng bằng Escape và trả focus cho nút mở.
- Checkout bằng Cash tạo đúng một order, xóa Cart, giữ nguyên số lượng/option/ghi chú/delivery note, hiện thông báo local success rồi chuyển sang Orders.
- Reorder khôi phục chính xác số lượng 2, đơn giá 19.74, hai option và ghi chú; không biến cấu hình thành món mặc định.

### Favorites, Profile và Wallet

- ArrowRight chuyển tab Favorites sang Dishes, cập nhật `aria-selected`, panel và focus.
- Xóa restaurant/dish độc lập cập nhật state, hiện empty state và CTA về Discover.
- Profile lưu và khôi phục sau reload bốn trường được hỗ trợ; UI nói rõ dữ liệu chỉ lưu cục bộ.
- Profile không giả vờ đổi mật khẩu; nút security bị vô hiệu hóa và giải thích thiếu backend bảo mật.
- Wallet dialog giữ focus, hỗ trợ Escape/focus return; top-up `$50` cập nhật ngay balance `$50.00` và activity `+$50.00 Credit`.

## An toàn dữ liệu render

Browser đã đưa payload HTML vào ba ranh giới dữ liệu lưu cục bộ:

- ghi chú Cart/Order và delivery note: `<img src=x onerror=window.__task7Xss=1>`;
- địa chỉ Profile: cùng payload ảnh;
- order độc hại riêng gồm tên món, option, note, SVG/script và URL ảnh `attacker.invalid`.

Kết quả: payload chỉ xuất hiện dưới dạng text, `window.__task7Xss` vẫn bằng 0, không có image/SVG/script được chèn, URL attacker không được dùng và ảnh Order vẫn được giải quyết từ `SavoraCatalog`. Cổng nguồn cũng xác nhận các renderer đã migrate không dùng `innerHTML =`, `outerHTML =`, `insertAdjacentHTML(`, `document.write(`, inline HTML event handler, `alert(` hoặc `setInterval(`.

## Asset self-hosting và readiness gate

- Header dùng `assets/vendor/fontawesome/css/all.min.css` và `assets/vendor/leaflet/leaflet.css`; footer dùng `assets/vendor/leaflet/leaflet.js`. Không còn cdnjs/unpkg trong Customer header/footer.
- Font Awesome local gồm `fa-solid-900.woff2` và `fa-regular-400.woff2`. Mỗi visual capture chờ `document.fonts.ready`, sau đó kiểm tra mọi `.fa-solid/.fa-regular` có Font Awesome family và pseudo-content khác rỗng.
- Bốn ảnh catalog nằm trong `assets/images/catalog/`. Discover xác minh 8 image instances; Product, Cart, Checkout và Orders ít nhất 1; Favorites 2. Ở cả ba breakpoint, ảnh báo đúng 800px natural width và không dùng fallback.
- Unknown, empty hoặc URL ngoài catalog được giải quyết sang local `assets/images/food-placeholder.svg`; browser xác minh fallback tải thành công ở 800×560.
- `css/style.css` và `css/customer_style.css` không còn URL `http(s)`: Google Fonts được bỏ để dùng system font stack; `shared-food-table.jpg`, `discovery-pasta.jpg` và `produce-promo.jpg` nằm local trong `assets/images/backgrounds/`.
- Browser readiness gate kiểm tra hai background đang hiển thị trên Discover ở từng breakpoint: URL phải cùng origin, sự kiện load phải thành công và natural size phải lớn hơn 0. Hero/promo vẫn khai báo ivory/forest gradient fallback để nội dung giữ tương phản nếu bitmap không thể vẽ.
- Route identity được so sánh với pathname mong đợi ngay trước capture; query của Product không làm thay đổi identity.
- Shared header map đặt title riêng cho đủ tám Customer route; visual gate so exact title ở cả ba breakpoint (Product chuyển sang title theo dish sau khi render).

Nguồn và license đầy đủ nằm trong `assets/THIRD_PARTY_NOTICES.md`:

- Font Awesome Free 6.4.0: CSS và WOFF2 từ cdnjs; icons CC BY 4.0, fonts SIL OFL 1.1, code MIT; upstream `LICENSE.txt` được lưu local.
- Leaflet 1.9.4: dist từ unpkg/upstream; BSD 2-Clause; license được lưu local.
- Ảnh catalog và background: bảy URL `images.unsplash.com` chính xác cùng local filename được ghi trong notice, dùng theo Unsplash License. Runtime không hotlink ảnh.

## Bàn phím và khả năng truy cập

- Header có nav có tên, trạng thái active do shared chrome quản lý. Menu mobile có focus-on-open, Escape/focus-return, outside-click/focus-return và link-click/navigation; desktop nav không bị tác động bởi logic mobile.
- Cart drawer và Wallet top-up có dialog semantics, initial focus, focus trap, Escape và focus return.
- Favorites dùng tab semantics và điều hướng phím mũi tên; tabpanel có focus indicator.
- Checkout dùng form/label/payment radio rõ ràng và có success feedback local.
- Nút xóa Cart có accessible name chứa tên món; Credit/Debit luôn có chữ, không truyền nghĩa chỉ bằng màu.
- Mỗi route có một hierarchy chính bắt đầu từ `<main>` và heading hiển thị.

## Kiểm tra hierarchy và responsive

Đối chiếu mockup được thực hiện theo hierarchy và hành vi, không sao chép cứng hình ảnh:

| Trang | Hierarchy đã quan sát |
| --- | --- |
| Discover | delivery/search hero → category chips → active-order/reward/promo → dishes → restaurants |
| Product | breadcrumb/media/restaurant → product facts → portion → add-ons → note → quantity/CTA |
| Cart | breadcrumb/intro → line items/add-more → order summary/promo/checkout |
| Checkout | heading/stepper → address → payment → promo/security → order summary |
| Orders | filter tabs → active-order tracker → local order history/reorder |
| Favorites | page intro → keyboard tabs → independent saved grid/empty CTA |
| Profile | identity → contact form → saved address → honest account-security card |
| Wallet | wallet intro/balance → activity → local-balance and clarity notes → top-up dialog |

Kích thước đo được:

| Nhóm | 1440 setting | 768 setting | 320 setting |
| --- | --- | --- | --- |
| Discover | nội dung khả dụng 1425, không overflow | 753, không overflow | 320, không overflow |
| Product | main 1180 | main 721 | main 288 |
| Sáu route còn lại | main 1180 | main 721 | main 304 |

Ở setting 1440/768, Chrome báo chiều rộng nội dung 1425/753 vì scrollbar dọc 15px. Mọi `scrollWidth` đều không lớn hơn `innerWidth`. Kiểm tra trực quan xác nhận desktop giữ bố cục nhiều cột đúng chỗ, tablet chuyển sang stack rõ ràng và 320px giữ CTA/control/text trong viewport.

## Ảnh bằng chứng

Thư mục: `.superpowers/sdd/customer-ui-2026-07-29/task-7-qa/`

Mỗi route có ba ảnh `{route}-1440.png`, `{route}-768.png`, `{route}-320.png`, với route:

- `discover`, `product`, `cart`, `checkout`
- `orders`, `favorites`, `profile`, `wallet`

Ảnh trạng thái tương tác bổ sung:

- `mobile-menu-320.png`
- `cart-drawer-320.png`
- `checkout-success-1440.png`
- `wallet-topup-dialog-1440.png`
- `favorites-empty-320.png`
- `map-fallback-1440.png`

Tổng cộng 30 PNG. Kết quả machine-readable của browser run nằm tại `task-7-qa/results.json`.

## Giới hạn và mối quan tâm còn lại

- Đây là local demo: profile, favorites, cart, orders và wallet nằm trong browser state; không có server sync, payment/bank thật hoặc password update.
- Font Awesome, ảnh catalog/background và Leaflet library không còn phụ thuộc runtime CDN; font chữ dùng system stack. Chỉ CARTO/OpenStreetMap raster tiles còn ngoài mạng và không critical. Browser test chủ động block chúng để chứng minh degraded state; các request `blockedReason: inspector` là do test, còn `net::ERR_ABORTED` phát sinh khi sweep chuyển route trước khi tile tải xong. Cả hai trường hợp đều được ghi trong `results.json`; map giữ nền local, route markers và thông báo rõ thay vì một vùng trống không giải thích.
- Không tạo commit vì workspace này không phải Git repository.
