/**
 * Dynamic Page Loader
 * Load HTML content vào div#content mà không cần reload trang
 */

const loadPage = (pageName) => {
  const contentDiv = document.getElementById('content');
  
  if (!contentDiv) {
    console.error('Div #content không tồn tại!');
    return;
  }

  // Hiển thị loading
  contentDiv.innerHTML = '<div class="loading" style="text-align: center; padding: 40px;"><p>Đang tải...</p></div>';

  // Fetch HTML file
  fetch(`./pages/${pageName}.html`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`Lỗi: ${response.status}`);
      }
      return response.text();
    })
    .then(html => {
      // Xóa các thẻ html, body, head không cần thiết, chỉ lấy nội dung
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Lấy toàn bộ nội dung từ body
      const bodyContent = doc.body.innerHTML;
      contentDiv.innerHTML = bodyContent;
      
      // Khôi phục Feather Icons nếu có
      if (typeof feather !== 'undefined') {
        feather.replace();
      }
      
      // Scroll lên đầu trang
      window.scrollTo(0, 0);
    })
    .catch(error => {
      contentDiv.innerHTML = `<div class="error" style="color: red; padding: 20px;">Lỗi tải trang: ${error.message}</div>`;
      console.error('Lỗi fetch:', error);
    });
};

// Cách sử dụng: loadPage('cart') - sẽ load ./pages/cart.html
/**
 * Load News Article
 * Load bài viết chi tiết từ news-detail.html với article ID
 */
const loadNewsArticle = (articleId) => {
  const contentDiv = document.getElementById('content');
  
  if (!contentDiv) {
    console.error('Div #content không tồn tại!');
    return;
  }

  // Hiển thị loading
  contentDiv.innerHTML = '<div class="loading" style="text-align: center; padding: 40px;"><p>Đang tải...</p></div>';

  // Fetch HTML file
  fetch(`./pages/news-detail.html`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`Lỗi: ${response.status}`);
      }
      return response.text();
    })
    .then(html => {
      // Xóa các thẻ html, body, head không cần thiết, chỉ lấy nội dung
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Lấy toàn bộ nội dung từ body
      const bodyContent = doc.body.innerHTML;
      contentDiv.innerHTML = bodyContent;
      
      // Set article ID vào content div để có thể tham chiếu
      contentDiv.setAttribute('data-article-id', articleId);
      
      // Khôi phục Feather Icons nếu có
      if (typeof feather !== 'undefined') {
        feather.replace();
      }
      
      // Scroll lên đầu trang
      window.scrollTo(0, 0);
    })
    .catch(error => {
      contentDiv.innerHTML = `<div class="error" style="color: red; padding: 20px;">Lỗi tải trang: ${error.message}</div>`;
      console.error('Lỗi fetch:', error);
    });
};

// Cách sử dụng: loadNewsArticle(1) - sẽ load bài viết thứ 1