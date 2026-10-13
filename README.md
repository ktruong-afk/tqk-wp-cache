# TQK WP Cache

Plugin tăng tốc WordPress: lưu mỗi trang thành file HTML tĩnh và trả file đó **trước khi WordPress khởi động**, kèm các tối ưu phía trình duyệt để trang tải nhẹ và nhanh hơn. Giao diện quản trị có tiếng Việt và English.

> Repo này chỉ chứa phần giới thiệu. File cài đặt của plugin được phát hành riêng bởi tác giả.

## Plugin làm được gì

**Page cache**
- Trả trang đã lưu ngay từ drop-in `advanced-cache.php`, không phải chạy WordPress; hỗ trợ ETag / `304 Not Modified`, nén gzip tĩnh, cache riêng cho điện thoại (tuỳ chọn), tách http và https.
- Không bao giờ cache người đã đăng nhập, giỏ hàng / thanh toán WooCommerce, POST, AJAX, REST, tìm kiếm, 404, bài có mật khẩu, trang có `Set-Cookie`.
- Bỏ qua tham số theo dõi (`utm_*`, `fbclid`, `gclid`…) khi tạo khoá cache, tham số lạ thì không cache nên không làm đầy ổ đĩa.
- Nếu plugin cache khác đã chiếm `advanced-cache.php`, TQK không ghi đè mà báo ngay ở trang quản trị.

**Xoá cache thông minh**
- Mỗi trang gắn thẻ theo nội dung nó hiển thị; sửa một bài hay một sản phẩm chỉ xoá đúng các trang liên quan.
- Xoá cache chỉ đánh dấu cũ, khách vào cùng lúc nhận bản cũ trong khi **một** request dựng lại trang, nên không bị tràn CPU. Toàn site dựng song song tối đa 4 trang.
- Bộ làm nóng cache (Preload) chạy tuần tự và tự nghỉ lâu hơn sau trang chậm.

**Tối ưu phía trình duyệt**
- Gộp CSS / JS an toàn theo thứ tự (chỉ gộp các thẻ nằm liền nhau, giữ nguyên script inline).
- Trì hoãn JavaScript tới lần tương tác đầu tiên (không đụng `type="module"`, JSON, importmap, `noscript`), có danh sách loại trừ.
- Instant Page: tải trước trang khi rê chuột, bỏ qua các liên kết có tác dụng phụ (`add-to-cart`, `logout`, `_wpnonce`…).
- Lazy-load ảnh và iframe (trừ ảnh đầu tiên), minify HTML, hint cho LCP, font swap.

**Dọn dẹp và quản trị**
- Dọn cơ sở dữ liệu: bản sửa đổi, transient hết hạn, auto-draft cũ, postmeta mồ côi; quản lý thùng rác theo post type.
- Điều khiển Heartbeat, Admin Turbo, tích hợp Cloudflare, tuỳ chọn tắt kiểm tra cập nhật ở mỗi trang quản trị.

## Hiệu năng

Theo số đo của tác giả trên một site thử nghiệm (theme MasterStudy + Elementor + WooCommerce, chạy local), thời gian phản hồi đầu tiên (TTFB) của trang chủ giảm từ trung vị khoảng 555 ms xuống khoảng 12 ms. Tốc độ thực tế phụ thuộc máy chủ, theme và plugin của bạn; plugin không hứa điểm PageSpeed cụ thể nào.

## Bản PRO

TQK WP Cache PRO là plugin bổ sung riêng, chạy trên plugin này. Plugin miễn phí không chứa mã của bản PRO. Bản PRO thêm:
- xoá và làm mới cache thông minh (chỉ dựng lại các trang thật sự hiển thị nội dung đã đổi);
- bản đồ phụ thuộc SQL của từng trang;
- object cache bền vững và cache truy vấn;
- cache cho thành viên đã đăng nhập ở những đường dẫn bạn chọn;
- tối ưu ảnh và WebP.

## Yêu cầu

- WordPress 6.0 trở lên, PHP 8.0 trở lên.
- Quyền ghi vào `wp-content/` (để đặt drop-in) và `wp-config.php` (để bật `WP_CACHE`; nếu không ghi được thì thêm tay `define( 'WP_CACHE', true );`).
- Chạy được trên Apache, LiteSpeed và nginx.

## Cài đặt

Trong WordPress vào **Plugins → Add New → Upload Plugin**, chọn file zip của plugin rồi **Activate**. Cài đặt nằm ở mục **TQK WP Cache** trong menu quản trị.

## Giới hạn đã biết

- Cache theo thiết bị chỉ tách khi bật "Mobile Cache"; không tách theo tiền tệ hoặc ngôn ngữ ngoài những gì có trong URL hoặc cookie đã khai báo.
- Site đa domain (domain mapping) cần filter `tqk_cache_allow_host`.

## Tác giả và giấy phép

Trương Quang Khải · [conggame.online](https://conggame.online) · [Facebook](https://www.facebook.com/Unk.khai/)

Giấy phép GPL v2 trở lên.
