# Tài Liệu Tổng Quan Hệ Thống PHPCHINH

## 1. Mục tiêu hệ thống

PHPCHINH là hệ thống bán hàng và chăm sóc thú cưng tích hợp:

- Website người dùng: xem sản phẩm, dịch vụ, đặt lịch, quản lý tài khoản.
- Backoffice nhân sự (staff): xử lý lịch hẹn, đơn hàng, vận hành nghiệp vụ.
- Backoffice quản trị (admin): quản trị danh mục, sản phẩm, dịch vụ, khách hàng, doanh thu, voucher.
- API tập trung theo mô hình `index.php?api=...` phục vụ toàn bộ màn hình frontend.

## 2. Kiến trúc tổng thể

### 2.1 Kiến trúc ứng dụng

- Backend: PHP thuần (procedural), điểm vào chính tại `index.php`.
- Database: MySQL, truy cập qua `mysqli`.
- Frontend: HTML/CSS/JavaScript thuần, không dùng SPA framework.
- Rendering: đa phần client-side load trang con vào shell chính (`home.html`) bằng hàm điều hướng động.

### 2.2 Kiến trúc module API

`index.php` đóng vai trò API gateway nội bộ:

- Nạp cấu hình môi trường và helper dùng chung.
- Kết nối DB, chuẩn hóa bảng/cột cần thiết (migrations runtime).
- Điều phối API theo 3 vùng nghiệp vụ:
  - `app_handle_admin_api(...)`
  - `app_handle_staff_api(...)`
  - `app_handle_user_api(...)`

Nguồn xử lý chính nằm tại:

- `Giao Diện/admin/get_services.php`
- `Giao Diện/staff/get_services.php`
- `Giao Diện/user/home_api.php`

## 3. Cấu trúc thư mục chính

- `index.php`: entrypoint backend + router API.
- `oauth-config.php`: cấu hình OAuth/SMTP local.
- `anhdata/`: dữ liệu ảnh media (products, services, avatars...).
- `Giao Diện/`: toàn bộ frontend.
  - `dang-nhap.html`: màn hình đăng nhập.
  - `user/home.html`: shell giao diện người dùng.
  - `user/pages/*.html`: các trang nội dung động (cửa hàng, dịch vụ, đặt lịch...).
  - `staff/*.html`: màn hình nhân viên.
  - `admin/*.html`: màn hình quản trị.
  - `common/*.js`: script dùng chung (validation, popup policy, realtime session).

## 4. Công nghệ đang sử dụng

## 4.1 Backend

- PHP (thuần, không framework).
- MySQLi (`mysqli`) cho truy vấn DB.
- JSON API response chuẩn hóa qua `app_json_response(...)`.
- Runtime schema evolution:
  - Tự kiểm tra bảng/cột.
  - Tự tạo hoặc bổ sung cột khi thiếu.

## 4.2 Frontend

- HTML5 + CSS3 + JavaScript ES6+.
- Bootstrap 5 (layout/components).
- Feather Icons, Bootstrap Icons, Font Awesome.
- Google Fonts (Poppins, Inter, Be Vietnam Pro, Playfair Display theo từng trang).

## 4.3 Đồng bộ realtime

Hệ thống dùng kết hợp:

- `localStorage` event (`storage`) cho sync đa tab.
- `BroadcastChannel` cho sync nhanh trong cùng origin.
- `AppSessionRealtime` (script nội bộ) để publish/subscribe event app-level.

Ví dụ kênh realtime nổi bật:

- `serviceBookingSyncSignal` / `service-booking-sync`: đồng bộ trạng thái lịch giữa user và staff.
- `onlineOrderSyncSignal` / `online-order-sync`: đồng bộ trạng thái đơn.

## 5. Chức năng nghiệp vụ chính

## 5.1 Người dùng

- Duyệt sản phẩm, lọc, tìm kiếm.
- Xem chi tiết sản phẩm (quick view), thêm giỏ hàng/yêu thích.
- Duyệt dịch vụ, xem chi tiết dịch vụ, đặt lịch.
- Theo dõi lịch sử đặt dịch vụ, hủy lịch chờ duyệt.
- Quản lý hồ sơ, địa chỉ, phương thức thanh toán, bảo mật.
- Đánh giá đơn hàng.

## 5.2 Nhân viên (staff)

- Quản lý lịch hẹn dịch vụ.
- Duyệt/từ chối lịch hẹn.
- Kiểm tra xung đột lịch trước khi duyệt.
- Nhận thông tin xung đột chi tiết để xử lý nghiệp vụ.

## 5.3 Quản trị (admin)

- Quản lý sản phẩm, danh mục, dịch vụ.
- Quản lý khách hàng, đơn hàng, voucher, báo cáo.
- Quản lý dữ liệu vận hành tổng thể.

## 6. Luồng dữ liệu lịch hẹn dịch vụ

## 6.1 Tạo lịch (user)

1. User gửi yêu cầu `create_service_booking`.
2. Backend xác thực user và chuẩn hóa dữ liệu lịch.
3. Lưu vào bảng `lichhen` với trạng thái `choduyet`.
4. Frontend phát sự kiện realtime để staff tự cập nhật danh sách.

## 6.2 Duyệt lịch (staff)

1. Staff gọi `update_service_booking_status`.
2. Backend kiểm tra trùng lịch với lịch đã duyệt.
3. Nếu trùng, trả `BOOKING_TIME_CONFLICT` + danh sách lịch trùng.
4. Nếu hợp lệ, cập nhật trạng thái và phát sự kiện realtime để user cập nhật tức thì.

## 6.3 Hủy lịch (user)

1. User gọi `cancel_service_booking_by_user`.
2. Backend kiểm tra quyền sở hữu + trạng thái chỉ cho phép `choduyet`.
3. Cập nhật thành `huy`.
4. Phát tín hiệu realtime để staff và user khác tab cập nhật đồng bộ.

## 7. Xác thực, OAuth và OTP

## 7.1 Xác thực tài khoản

- Có login thường (email/sđt + mật khẩu).
- Có social login (Google/Facebook).
- Có luồng hoàn tất hồ sơ social nếu thiếu thông tin.

## 7.2 OAuth provider

- Google: scope `openid email profile`.
- Facebook: scope `email public_profile`.
- Callback local (Laragon, port 8888):
  - `http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=google`
  - `http://localhost:8888/phuocthanh/PHPCHINH/index.php?api=social_oauth_callback&provider=facebook`

## 7.3 OTP email

- Dùng SMTP để gửi mã xác thực đăng ký/quên mật khẩu.
- Backend có triển khai SMTP client bằng socket trực tiếp (không phụ thuộc thư viện ngoài).

## 8. Công nghệ lưu trữ client-side

Hệ thống dùng:

- `localStorage`: giỏ hàng, wishlist, profile cục bộ, tín hiệu sync.
- `sessionStorage`: auth session tạm, trạng thái phiên.
- Cơ chế đồng bộ dữ liệu local/server theo chữ ký dữ liệu để giảm ghi đè sai.

## 9. Tích hợp chia sẻ mạng xã hội

Trong popup chi tiết sản phẩm/dịch vụ hiện có:

- Facebook share.
- Messenger (ưu tiên deep-link app, fallback web).
- Zalo (ưu tiên deep-link app, fallback web).
- Sao chép link.
- Chia sẻ nhanh (`navigator.share`) khi trình duyệt hỗ trợ.

Lưu ý:

- Link `localhost` không tạo preview card đầy đủ trên Facebook/Zalo.
- Muốn hiển thị ảnh/mô tả tự động cần URL public.

## 10. Bảo mật và vận hành

- Không commit secret thật lên Git.
- Cấu hình OAuth/SMTP nên đọc từ biến môi trường hoặc file local không public.
- Bật GitHub push protection/secret scanning để chặn rò rỉ khóa.
- Luôn validate quyền user trước thao tác nhạy cảm (hủy lịch, cập nhật trạng thái...).

## 11. Môi trường chạy local

- OS: Windows.
- Stack local: Laragon + Apache + MySQL.
- URL phổ biến: `http://localhost:8888/phuocthanh/PHPCHINH/`.

## 12. Checklist kiểm thử khuyến nghị

- Đăng nhập thường, social login, OTP.
- CRUD sản phẩm/dịch vụ từ admin.
- Đặt lịch từ user, duyệt/từ chối từ staff.
- Kiểm tra xung đột lịch khi duyệt.
- Hủy lịch chờ duyệt từ user.
- Kiểm tra realtime giữa các tab và giữa user/staff.
- Kiểm tra chia sẻ Facebook/Messenger/Zalo trên môi trường public.

## 13. Hướng mở rộng đề xuất

- Chuẩn hóa thành `.env` + loader cấu hình tập trung.
- Tách API routes theo module rõ hơn (REST naming).
- Thêm logging/audit trail cho thao tác staff/admin.
- Thêm test tự động cho luồng booking, auth và sync realtime.
- Tối ưu SEO/share preview bằng Open Graph metadata cho URL sản phẩm/dịch vụ.
