# DAC TA HE THONG PHPCHINH

Ngay cap nhat: 2026-04-18
Pham vi ra soat: backend PHP + frontend HTML/CSS/JS + dong bo du lieu va realtime
Co so ra soat: ma nguon thuc te trong du an (khong chi dua tren tai lieu cu)

## 1. Tong quan he thong

PHPCHINH la he thong ban hang va cham soc thu cung gom 3 kenh van hanh:

- Kenh nguoi dung: xem/dang ky tai khoan, dat lich dich vu, mua hang online, quan ly yeu thich va gio hang, danh gia don.
- Kenh nhan vien (staff): xu ly lich hen, ban tai quay (POS), xem doanh thu va bao cao van hanh.
- Kenh quan tri (admin): CRUD danh muc, danh muc con, san pham, dich vu, voucher, khach hang, thu cung; duyet don online.

Kien truc backend la mot front-controller duy nhat tai index.php va route theo query string ?api=...

## 2. Kien truc ky thuat

### 2.1 Backend

- Cong nghe: PHP thuan procedural + mysqli.
- Entry point: index.php.
- Kieu API: JSON response thong nhat qua app_json_response(...).
- Kieu routing: index.php nap 3 module va goi lan luot:
  - app_handle_admin_api(...)
  - app_handle_staff_api(...)
  - app_handle_user_api(...)
- Co che khoi tao schema runtime:
  - Tu dong CREATE TABLE neu chua ton tai.
  - Tu dong ALTER TABLE bo sung cot/index khi thieu.
  - Tu dong dong bo du lieu tong hop (vd: luot dat dich vu, luot mua san pham, doanh thu).

### 2.2 Frontend

- Stack: HTML + CSS + JavaScript thuan.
- Thu muc giao dien:
  - Giao Diện/user
  - Giao Diện/staff
  - Giao Diện/admin
- Script dung chung:
  - Giao Diện/common/app-session-realtime.js
  - Giao Diện/common/app-form-validation.js
  - Giao Diện/common/app-popup-policy.js

### 2.3 Co che realtime va dong bo client

He thong dung ket hop:

- localStorage event (storage) de dong bo cross-tab.
- BroadcastChannel de pub/sub nhanh trong cung origin.
- Co co che phat su kien app-level trong client cho cac man hinh booking/don.

## 3. Cau truc thu muc quan trong

- index.php: front controller + db helper + migration helper + redirect root.
- oauth-config.php: OAuth va SMTP local config.
- Giao Diện/admin/get_services.php: API admin.
- Giao Diện/staff/get_services.php: API staff + booking + doanh thu + POS.
- Giao Diện/user/home_api.php: API user + auth + OAuth + OTP + order/review.
- anhdata/: luu media (categories/products/services/chat/avatar).

## 4. Vong doi request backend

1. Frontend goi index.php?api=<ten_api>.
2. index.php nap config local + env, dinh nghia helper chung.
3. Ket noi DB (tu detect port 3306/3307/... va db name phu hop).
4. Nap cac module API admin/staff/user.
5. Chay cac ham ensure schema can thiet.
6. Dieu phoi vao handler theo API name.
7. Tra JSON va dong ket noi.
8. Neu khong co ?api thi redirect ve trang dang nhap giao dien.

## 5. Danh sach API theo module

## 5.1 Admin APIs

API theo ten:

- upload_image
- get_categories
- get_users
- get_home_overview
- get_voucher_games
- get_vouchers
- manage_entity
- get_online_orders
- update_online_order_status

manage_entity ho tro action create/update/delete cho cac entity:

- vouchers
- services
- service_categories
- products
- categories
- category_subcategories
- customers
- pets

Nghiep vu noi bat:

- Upload anh va tra URL public.
- CRUD voucher co rang buoc minigame + level + ton kho + thoi gian.
- CRUD san pham co discount schedule va auto xac dinh trang thai ton.
- CRUD thu cung co phan biet nguon thu cung cua_hang/khach_hang.
- Quan ly don online (cho_duyet/da_duyet/tu_choi).
- Khi duyet don online:
  - Tru ton kho.
  - Dong bo trang thai don tong.
  - Cap nhat chi tieu khach hang.
  - Xoa gio hang lien quan.

## 5.2 Staff APIs

API theo ten:

- get_home_overview
- get_revenue_dashboard
- checkout_pos_order
- get_sales
- get_sale_order_detail
- create_service_booking
- get_service_bookings
- update_service_booking_status
- cancel_service_booking_by_user
- get_services
- get_featured_services
- get_products
- get_product_reviews
- get_customers
- get_pets

Nghiep vu noi bat:

- Tao don POS, tru ton kho, luu chi tiet don.
- Dashboard doanh thu theo day/week/month/year.
- Dong bo bang doanhthu tu don + lich.
- Booking service:
  - Tao lich cho user.
  - Duyet/tu choi/huy lich.
  - Kiem tra trung lich khi duyet (BOOKING_TIME_CONFLICT).
- Cung cap danh sach san pham/dich vu/khach hang/thu cung cho staff UI.

## 5.3 User APIs

API theo ten:

- get_user_collections
- sync_user_collections
- toggle_product_favorite
- update_user_avatar
- upload_chat_media
- get_voucher_hunt_list
- claim_voucher_game
- send_email_verification_code
- verify_email_verification_code
- reset_password_with_code
- register_user
- get_home_categories
- social_oauth_start
- social_oauth_callback
- get_social_pending_profile
- complete_social_signup
- login_user
- login_admin_staff
- create_online_order
- get_order_reviews
- save_order_review

Nghiep vu noi bat:

- Dong bo wishlist/gio hang giua local va server.
- Upload media chat (image/video/audio), toi da 100MB, loc mime.
- He thong OTP email cho dang ky va quen mat khau.
- OAuth login (Google/Facebook) + pending profile completion.
- Dat don online, tao don tong noi bo va chi tiet don.
- Danh gia don theo order code sau khi don da duyet.

## 6. Mo hinh du lieu chinh (tong quan)

Cac bang du lieu duoc su dung va/hoac tao runtime:

- nguoidung
- khachhang
- danhmuc
- danhmuccon
- sanpham
- dichvu
- danhmucdichvu
- thucung
- lichhen
- giohang
- chitietgiohang
- yeuthich
- donhang
- donhang_chitiet
- donhang_online
- lichsudonhang
- doanhthu
- magiamgia
- magiamgia_nguoidung
- email_verification_tokens
- danhgiasanpham

Dac diem quan trong:

- He thong su dung migration theo code thay vi migration script rieng.
- Co bo sung index/unique de dam bao nghiep vu:
  - uq_ctgh_cart_product
  - uq_yeuthich_user_product
  - uq_magiamgia_code
  - uq_danhgia_order_customer_product
- Nhieu bang co tinh backward-compatible voi schema cu (doi ten cot, cot thay the).

## 7. Luong nghiep vu trong tam

## 7.1 Dat lich dich vu

1. User goi create_service_booking.
2. He thong xac thuc user role = user.
3. Tao/gan khach hang + tim dich vu + resolve datetime.
4. Luu lich vao lichhen voi trangthai choduyet.
5. Staff lay danh sach bang get_service_bookings.
6. Staff goi update_service_booking_status de duyet/huy.
7. Neu duyet, he thong kiem tra conflict theo khung gio/ngay.

## 7.2 Don online

1. User goi create_online_order.
2. Tao ban ghi donhang (noi bo) + donhang_chitiet.
3. Tao ban ghi donhang_online trang thai cho_duyet.
4. Admin/staff duyet bang update_online_order_status.
5. Khi da_duyet:
  - Tru ton kho.
  - Chuyen trang thai don tong.
  - Dong bo chi tieu khach hang.
  - Gan co da_cong_chi_tieu.

## 7.3 OTP dang ky/quen mat khau

1. send_email_verification_code tao OTP hash va gui SMTP.
2. verify_email_verification_code kiem tra ma.
3. register_user hoac reset_password_with_code consume OTP.

## 7.4 Social login

1. social_oauth_start tao state + redirect provider.
2. social_oauth_callback doi access token + lay profile.
3. Neu da co user theo email: dang nhap.
4. Neu chua co: luu pending profile session.
5. complete_social_signup tao user chinh thuc.

## 8. Bao mat va rui ro hien trang

## 8.1 Phat hien quan trong

File oauth-config.php dang chua secret that:

- Google client secret
- Facebook app secret
- SMTP username/password

Day la rui ro bao mat nghiem trong neu file bi chia se/commit.

## 8.2 Danh gia co che bao mat hien co

Diem tot:

- Co check role cho cac API quan trong.
- Co hash password (bcrypt cho luong moi), support verify compat du lieu cu.
- Co OTP co expiry/attempt_count.
- Co validate input o nhieu endpoint.

Diem can cai thien:

- Chua thay co JWT/session server-side cho API; dua nhieu vao payload user_id/email tu client.
- Nhieu API chua co rate limit.
- Chua co CSRF strategy ro rang cho mot so luong POST.
- Chua co audit log day du cho thao tac nhay cam admin/staff.

## 9. Van hanh va cau hinh

Moi truong local mac dinh:

- Windows + Laragon
- URL: http://localhost:8888/phuocthanh/PHPCHINH/

Cau hinh can quan ly:

- DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME
- GOOGLE_OAUTH_*
- FACEBOOK_OAUTH_*
- SMTP_*

Khuyen nghi:

- Tach toan bo secret sang .env (khong commit).
- Dung config loader tap trung.
- Bat secret scanning tren repo.

## 10. Giam sat, logging, va test

Hien trang:

- Chua co module logging/audit rieng.
- Chu yeu tra loi loi qua JSON message.

Khuyen nghi test toi thieu:

- Login user/admin/staff.
- Dang ky + OTP + reset password.
- OAuth callback flow (Google/Facebook).
- Dat lich/duyet lich/xung dot lich.
- Tao don online + duyet don + tru kho.
- Checkout POS.
- Dong bo wishlist/gio hang.
- Danh gia don sau hoan thanh.

## 11. De xuat cai tien kien truc

Muc uu tien cao:

1. Dua secret ra khoi oauth-config.php, dung bien moi truong.
2. Chuan hoa auth token/session server-side, giam phu thuoc user_id tu client.
3. Tach API route thanh cac module ro rang theo domain (auth, order, booking, catalog).
4. Them logging/audit cho update status don/lich va thao tac admin.
5. Bo sung test tu dong cho luong booking/order/auth.

Muc uu tien trung binh:

1. Them pagination/filter chuan cho endpoint list lon.
2. Chuan hoa enum/status map giua cac module.
3. Tang cuong validation schema request/response.

## 12. Tom tat hien trang

He thong da co pham vi chuc nang day du cho mot nen tang ban hang + dich vu thu cung da vai tro.

Diem manh:

- API tap trung mot dau moi, de trien khai nhanh.
- Runtime migration giup chay duoc tren nhieu schema cu.
- Bao phu nghiep vu da kenh: user/staff/admin + online/POS + booking.

Diem han che:

- Co rui ro bao mat do luu secret trong file code.
- Architecture procedural lon dan, de tao no ky thuat khi mo rong.
- Chua co tang quan sat va kiem thu tu dong manh.

---

Tai lieu nay la ban dac ta hien trang (as-is) dua tren ra soat ma nguon thuc te tai thoi diem 2026-04-18.
Neu can, co the tao them ban dac ta to-be (kien truc muc tieu) va roadmap chuyen doi theo sprint.

## 13. Dac ta use case chinh

Phan nay bo sung dac ta use case o muc nghiep vu, tap trung vao cac luong cot loi cua he thong.

## UC-01 Dang ky tai khoan user bang OTP email

- Muc tieu: Tao tai khoan user moi hop le.
- Tac nhan chinh: Khach chua co tai khoan.
- Tac nhan phu: Dich vu email SMTP.
- API lien quan: send_email_verification_code, verify_email_verification_code, register_user.
- Tien dieu kien:
  - Email chua ton tai trong bang nguoidung.
  - Ten/email khong chua tu khoa reserved (admin/staff).
- Hau dieu kien thanh cong:
  - Ban ghi moi duoc tao trong nguoidung voi role user.
  - Co the tao/gan khachhang tuong ung (neu bang khachhang ton tai).
- Luong chinh:
  1. Nguoi dung nhap ten, email, mat khau.
  2. He thong gui OTP qua email.
  3. Nguoi dung nhap OTP 6 chu so.
  4. He thong verify OTP hop le.
  5. He thong tao tai khoan user, hash mat khau bcrypt.
  6. He thong tra ket qua dang ky thanh cong.
- Luong ngoai le:
  - E1: Email da ton tai -> tra 409.
  - E2: OTP sai/het han/qua so lan thu -> tra 400.
  - E3: SMTP that bai -> tra 500.

## UC-02 Dang nhap he thong theo vai tro

- Muc tieu: Xac thuc va cho phep dang nhap dung cong (user hoac admin/staff).
- Tac nhan chinh: Nguoi dung da co tai khoan.
- API lien quan: login_user, login_admin_staff.
- Tien dieu kien:
  - Tai khoan ton tai trong nguoidung.
  - Mat khau hop le.
- Hau dieu kien thanh cong:
  - Tra payload user co role va thong tin phien cho client.
- Luong chinh:
  1. Nguoi dung nhap identifier (email/username) + mat khau.
  2. He thong tim tai khoan va verify mat khau.
  3. He thong normalize role (user/staff/admin).
  4. He thong tra thong tin dang nhap thanh cong.
- Luong ngoai le:
  - E1: Sai tai khoan/mat khau -> 401.
  - E2: User dang nhap cong admin/staff hoac nguoc lai -> 403.
  - E3: Bang nguoidung khong ton tai -> 500.

## UC-03 Dang nhap social OAuth (Google/Facebook)

- Muc tieu: Dang nhap nhanh bang tai khoan social.
- Tac nhan chinh: Nguoi dung.
- Tac nhan phu: OAuth provider (Google/Facebook).
- API lien quan: social_oauth_start, social_oauth_callback, get_social_pending_profile, complete_social_signup.
- Tien dieu kien:
  - Da cau hinh OAuth client id/secret va redirect URI.
- Hau dieu kien thanh cong:
  - Neu email da ton tai: dang nhap ngay.
  - Neu chua ton tai: luu pending profile, hoan tat bo sung thong tin roi tao tai khoan.
- Luong chinh:
  1. Nguoi dung chon dang nhap Google/Facebook.
  2. He thong redirect den provider voi state.
  3. Provider callback ve he thong voi code.
  4. He thong doi token, lay profile.
  5. He thong upsert user hoac tao pending profile.
  6. Client nhan bridge payload va cap nhat phien.
- Luong ngoai le:
  - E1: State mismatch hoac user cancel -> dang nhap that bai.
  - E2: Chua cau hinh provider -> 500.
  - E3: Tai khoan social co reserved keyword -> 403.

## UC-04 Dat lich dich vu

- Muc tieu: User tao lich hen dich vu cho thu cung.
- Tac nhan chinh: User.
- Tac nhan phu: Staff (nhan xu ly sau do).
- API lien quan: create_service_booking.
- Tien dieu kien:
  - User da dang nhap va role user.
  - Co day du thong tin co ban: ten, SDT, dich vu, ngay, khung gio.
- Hau dieu kien thanh cong:
  - Tao ban ghi lichhen voi trangthai choduyet.
  - Cap nhat bo dem luot dat dich vu.
- Luong chinh:
  1. User nhap thong tin dat lich.
  2. He thong xac thuc user.
  3. He thong tim/tao khachhang, resolve service, resolve datetime.
  4. He thong luu lichhen o trang thai choduyet.
  5. He thong tra ma lich hen va trang thai.
- Luong ngoai le:
  - E1: Chua dang nhap -> 401.
  - E2: Role khong hop le -> 403.
  - E3: Thieu du lieu dat lich -> 400.

## UC-05 Staff duyet hoac tu choi lich hen

- Muc tieu: Staff cap nhat trang thai lich hen.
- Tac nhan chinh: Staff.
- API lien quan: get_service_bookings, update_service_booking_status.
- Tien dieu kien:
  - Lich hen ton tai.
  - Trang thai cap nhat thuoc tap cho phep.
- Hau dieu kien thanh cong:
  - Lich duoc cap nhat trangthai/ghi chu nhan vien.
  - Co lich su status_updates trong metadata.
- Luong chinh:
  1. Staff xem danh sach lich.
  2. Staff chon lich can xu ly va trang thai moi.
  3. He thong tai lich hien tai.
  4. Neu duyet hoanthanh: check xung dot khung gio.
  5. He thong cap nhat lichhen.
  6. He thong dong bo bo dem luot dat dich vu.
- Luong ngoai le:
  - E1: Lich khong ton tai -> 404.
  - E2: Trang thai khong hop le -> 400.
  - E3: Trung lich khi duyet -> 409 + BOOKING_TIME_CONFLICT.

## UC-06 User huy lich cho duyet

- Muc tieu: User huy lich khi con o trang thai cho duyet.
- Tac nhan chinh: User.
- API lien quan: cancel_service_booking_by_user.
- Tien dieu kien:
  - Lich ton tai va user la chu so huu hop le.
  - Lich dang o trang thai choduyet.
- Hau dieu kien thanh cong:
  - Lich doi thanh huy.
  - Metadata ghi nhan customer_cancel_note va status update.
- Luong chinh:
  1. User gui yeu cau huy kem thong tin xac thuc.
  2. He thong xac minh ownership theo user_id/email/phone.
  3. He thong check trang thai hien tai.
  4. He thong cap nhat trangthai = huy.
  5. He thong tra ket qua thanh cong.
- Luong ngoai le:
  - E1: Khong xac minh duoc tai khoan -> 401.
  - E2: Khong phai chu lich -> 403.
  - E3: Lich khong con la choduyet -> 409 BOOKING_STATUS_LOCKED.

## UC-07 User tao don hang online

- Muc tieu: Tao don hang online cho kenh user.
- Tac nhan chinh: User/Khach hang.
- Tac nhan phu: Admin/Staff (duyet don).
- API lien quan: create_online_order.
- Tien dieu kien:
  - Co thong tin nhan hang hop le.
  - Gio hang co it nhat 1 item hop le.
- Hau dieu kien thanh cong:
  - Tao donhang (noi bo) + donhang_chitiet.
  - Tao donhang_online trang thai cho_duyet.
  - Reset gio hang cua tai khoan lien quan (neu co).
- Luong chinh:
  1. User gui thong tin khach, dia chi, item, tong tien.
  2. He thong chuan hoa item va xac thuc account (neu co).
  3. He thong tao don tong va chi tiet don trong transaction.
  4. He thong tao donhang_online.
  5. He thong tra ma don va trang thai cho_duyet.
- Luong ngoai le:
  - E1: Thieu du lieu bat buoc -> 400.
  - E2: Item khong hop le -> 400.
  - E3: Loi transaction luu don -> 500.

## UC-08 Admin/Staff duyet don online

- Muc tieu: Duyet hoac tu choi don online va dong bo he thong.
- Tac nhan chinh: Admin/Staff.
- API lien quan: get_online_orders, update_online_order_status.
- Tien dieu kien:
  - Don online ton tai.
  - Trang thai cap nhat hop le (cho_duyet/da_duyet/tu_choi).
- Hau dieu kien thanh cong:
  - Don online duoc cap nhat trang thai.
  - Neu da_duyet: tru ton kho, cap nhat don tong, dong bo chi tieu khach hang.
- Luong chinh:
  1. Nhan vien quan tri tai danh sach don online.
  2. Chon don va trang thai moi.
  3. He thong khoa don muc tieu.
  4. Neu da_duyet: tru ton kho theo chi tiet don.
  5. He thong cap nhat donhang_online + donhang.
  6. He thong ghi lich su don hang (neu co bang lichsudonhang).
- Luong ngoai le:
  - E1: Don khong ton tai -> 404.
  - E2: Trang thai khong hop le -> 400.
  - E3: Khong du ton kho -> rollback transaction, tra 400.

## UC-09 Thanh toan POS tai quay

- Muc tieu: Staff lap hoa don va thanh toan truc tiep tai quay.
- Tac nhan chinh: Staff.
- API lien quan: checkout_pos_order.
- Tien dieu kien:
  - Co item hop le trong gio thanh toan.
  - Co du ton kho doi voi item san pham.
- Hau dieu kien thanh cong:
  - Tao donhang + donhang_chitiet voi nguon tai_quay.
  - Tru ton kho ngay lap tuc.
  - Trang thai don hoanthanh.
- Luong chinh:
  1. Staff nhap thong tin khach + item + phuong thuc thanh toan.
  2. He thong chuan hoa item product/service.
  3. He thong tru ton kho product.
  4. He thong tao don va chi tiet don trong transaction.
  5. He thong tra ma hoa don POS.
- Luong ngoai le:
  - E1: Gio hang rong -> 400.
  - E2: Khong du ton kho -> rollback, 400.
  - E3: Khong tao duoc don/chi tiet -> rollback, 400/500.

## UC-10 User danh gia don hang

- Muc tieu: User danh gia chat luong don da hoan thanh.
- Tac nhan chinh: User.
- API lien quan: get_order_reviews, save_order_review.
- Tien dieu kien:
  - Don online thuoc user theo email/phone.
  - Don o trang thai da_duyet.
- Hau dieu kien thanh cong:
  - Luu/Cap nhat danh gia trong danhgiasanpham theo tung san pham cua don.
- Luong chinh:
  1. User mo man hinh danh gia va nhap so sao + binh luan.
  2. He thong xac minh khach hang theo danh tinh.
  3. He thong xac minh ownership don va trang thai da_duyet.
  4. He thong tim danh sach san pham trong don.
  5. He thong insert/update danh gia theo sanpham.
  6. He thong tra ket qua da luu thanh cong.
- Luong ngoai le:
  - E1: Don khong thuoc user -> 404.
  - E2: Don chua duoc duyet -> 400.
  - E3: Thieu rating/comment hoac khong co san pham hop le -> 400.

## UC-11 User san voucher qua mini game

- Muc tieu: User nhan voucher neu vuot mini game.
- Tac nhan chinh: User.
- API lien quan: get_voucher_hunt_list, claim_voucher_game.
- Tien dieu kien:
  - Voucher con hieu luc, con so luong, dung game + level.
  - User co diem >= nguong yeu cau va is_win = true.
- Hau dieu kien thanh cong:
  - Tang luot nhan voucher cua user.
  - Giam so luong voucher ton.
- Luong chinh:
  1. User tai danh sach voucher hunt.
  2. User choi game va gui ket qua claim.
  3. He thong khoa voucher va ban ghi claim cua user.
  4. He thong validate game, level, score, so luot.
  5. He thong cap nhat magiamgia_nguoidung va tru so luong voucher.
  6. He thong tra thong tin voucher da nhan.
- Luong ngoai le:
  - E1: Chua vuot game/khong du diem -> 400.
  - E2: Sai game hoac level -> 400.
  - E3: Het luot/het han/het so luong -> 400.

## 14. Ma tran use case - API mapping nhanh

- UC-01: send_email_verification_code, verify_email_verification_code, register_user
- UC-02: login_user, login_admin_staff
- UC-03: social_oauth_start, social_oauth_callback, get_social_pending_profile, complete_social_signup
- UC-04: create_service_booking
- UC-05: get_service_bookings, update_service_booking_status
- UC-06: cancel_service_booking_by_user
- UC-07: create_online_order
- UC-08: get_online_orders, update_online_order_status
- UC-09: checkout_pos_order
- UC-10: get_order_reviews, save_order_review
- UC-11: get_voucher_hunt_list, claim_voucher_game

## 15. Ket qua dat duoc

Sau qua trinh phat trien va ra soat he thong, cac ket qua chinh dat duoc gom:

- Hoan thien mo hinh he thong da vai tro (user, staff, admin) tren cung mot backend tap trung.
- Xay dung luong nghiep vu cot loi day du:
  - Dang ky/dang nhap, OTP, social OAuth.
  - Dat lich dich vu, duyet lich, huy lich.
  - Don online, duyet don, POS tai quay.
  - Danh gia don hang, chuong trinh voucher mini game.
- Hinh thanh co che migration runtime giup he thong tu dong thich nghi voi schema DB cu.
- Trien khai co che dong bo du lieu client-side theo huong realtime da tab (localStorage + BroadcastChannel).
- Chuan hoa mot dau moi API thong qua index.php?api=..., de giam phan tan logic route.
- Cung cap bo dashboard va API tong hop phuc vu van hanh (doanh thu, don hang, lich hen, canh bao ton kho).

## 16. Han che

Mac du he thong da van hanh duoc cac luong chinh, van con cac han che can khac phuc:

- Bao mat cau hinh:
  - Van con nguy co ro ri secret neu de oauth-config.php trong nguon mo/commit.
- Bao mat API:
  - Nhieu endpoint dua vao user_id/email tu client, chua co lop auth token/session server-side dong nhat.
  - Chua thay co rate limiting va co che chan brute-force toan cuc.
  - Chua co chinh sach CSRF ro rang cho cac thao tac POST nhay cam.
- Kien truc:
  - Ma procedural tap trung nhieu logic vao cac file lon, kho mo rong va bao tri khi quy mo tang.
  - Chua tach ro service layer/repository layer.
- Van hanh chat luong:
  - Chua co bo test tu dong bao phu cac luong nghiep vu quan trong.
  - Chua co audit log day du cho thao tac admin/staff.
  - Chua co giam sat he thong tap trung (metrics, error tracking, alerting).

## 17. Huong phat trien

Huong phat trien de xuat theo 3 giai doan uu tien:

### 17.1 Ngan han (1-2 thang)

- Dua toan bo secret sang .env va bo co che load config tap trung.
- Chuan hoa xac thuc API (session server-side hoac token co ky so), giam tin tuong payload tu client.
- Bo sung rate limiting cho login, OTP, va cac API nhay cam.
- Them logging co cau truc cho cac su kien quan trong: duyet don, cap nhat lich, thay doi voucher.
- Viet bo test hoi quy cho cac use case UC-01 den UC-11 o muc API.

### 17.2 Trung han (3-6 thang)

- Refactor backend theo module domain: auth, booking, order, catalog, voucher.
- Tach logic truy van DB ra layer rieng de de test va de thay doi schema.
- Chuan hoa contract request/response (validation schema, ma loi, thong diep loi).
- Bo sung pagination/filter/sort dong nhat cho cac endpoint list lon.

### 17.3 Dai han (6-12 thang)

- Xay dung bo observability day du: metrics, tracing, centralized logging.
- Bo sung audit trail va phan quyen chi tiet hon theo vai tro va hanh dong.
- Toi uu hieu nang va kha nang mo rong:
  - Cache cho cac danh sach it thay doi.
  - Toi uu query nong va index theo hanh vi thuc te.
- Mo rong kha nang tich hop:
  - Cong thanh toan.
  - Van chuyen.
  - Thong bao da kenh (email/SMS/push) cho don hang va lich hen.