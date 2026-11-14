<?php
session_start();
require_once "db_connect.php";
$stat_universities = $conn->query("SELECT COUNT(*) AS n FROM universities WHERE is_active=1")->fetch_assoc()['n'];
$stat_programs     = (int)$conn->query("SELECT COUNT(*) AS n FROM programs p JOIN universities u ON u.university_id=p.university_id AND u.is_active=1")->fetch_assoc()['n'];
$stat_scholarships = (int)$conn->query("SELECT COUNT(*) AS n FROM scholarships s JOIN universities u ON u.university_id=s.university_id AND u.is_active=1")->fetch_assoc()['n'];
$stat_reviews      = $conn->query("SELECT COUNT(*) AS n FROM reviews WHERE status='Approved'")->fetch_assoc()['n'];
$countries_result  = $conn->query("
    SELECT c.country_id, c.country_name, c.country_code, c.currency_code,
           COUNT(u.university_id) AS uni_count
    FROM countries c
    LEFT JOIN universities u ON u.country_id=c.country_id AND u.is_active=1
    WHERE c.is_active=1 AND c.has_universities=1
    GROUP BY c.country_id, c.country_name, c.country_code, c.currency_code
    ORDER BY c.country_name");
$featured = $conn->query("SELECT u.university_id,u.name,u.city,u.university_type,u.image_url,c.country_name,c.country_code,c.currency_code,AVG(r.rating) AS avg_rating,COUNT(r.review_id) AS review_count FROM universities u JOIN countries c ON c.country_id=u.country_id LEFT JOIN reviews r ON r.university_id=u.university_id AND r.status='Approved' WHERE u.is_active=1 GROUP BY u.university_id,u.name,u.city,u.university_type,u.image_url,c.country_name,c.country_code,c.currency_code ORDER BY u.created_at DESC LIMIT 6");
$recent_reviews = $conn->query("SELECT r.rating,r.title,r.comment,u2.full_name AS reviewer,u.name AS university_name,u.university_id FROM reviews r JOIN users u2 ON u2.user_id=r.user_id JOIN universities u ON u.university_id=r.university_id WHERE r.status='Approved' ORDER BY r.created_at DESC LIMIT 3");

$country_photos = [
    'TH'=>'assets/images/country/thai1.jpg',
    'SG'=>'assets/images/country/sg1.jpg',
    'MY'=>'assets/images/country/malaysia.jpg',
];
// Fallback photo if a university has no image_url set in the database
$fallback_photo = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=600&q=80';
$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$country_badges=['TH'=>'🌟 Popular','SG'=>'#1 Ranked','MY'=>'Best Value'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Veya — Find Universities Abroad</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#fff;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(28px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes slideLeft{from{opacity:0;transform:translateX(-32px);}to{opacity:1;transform:translateX(0);}}
@keyframes slideRight{from{opacity:0;transform:translateX(32px);}to{opacity:1;transform:translateX(0);}}
@keyframes scaleIn{from{opacity:0;transform:scale(.94);}to{opacity:1;transform:scale(1);}}
@keyframes countUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
@keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
@keyframes pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.05);}}
@keyframes shimmer{0%{background-position:-200% 0;}100%{background-position:200% 0;}}

.reveal{opacity:0;transform:translateY(32px);transition:opacity .7s ease,transform .7s ease;}
.reveal.visible{opacity:1;transform:translateY(0);}
.reveal-left{opacity:0;transform:translateX(-32px);transition:opacity .7s ease,transform .7s ease;}
.reveal-left.visible{opacity:1;transform:translateX(0);}
.reveal-right{opacity:0;transform:translateX(32px);transition:opacity .7s ease,transform .7s ease;}
.reveal-right.visible{opacity:1;transform:translateX(0);}
.reveal-scale{opacity:0;transform:scale(.94);transition:opacity .6s ease,transform .6s ease;}
.reveal-scale.visible{opacity:1;transform:scale(1);}
.stagger-1{transition-delay:.1s!important;}
.stagger-2{transition-delay:.2s!important;}
.stagger-3{transition-delay:.3s!important;}
.stagger-4{transition-delay:.4s!important;}
.stagger-5{transition-delay:.5s!important;}
.stagger-6{transition-delay:.6s!important;}

/* ── NAV ── */
nav{
  position:fixed;top:0;left:0;right:0;z-index:200;
  padding:0 40px;height:72px;
  display:flex;align-items:center;justify-content:space-between;
  transition:background .3s,box-shadow .3s,backdrop-filter .3s;
}
nav.scrolled{
  background:rgba(255,255,255,.95);
  backdrop-filter:blur(16px);
  box-shadow:0 1px 24px rgba(10,22,40,.08);
}
.nav-logo{display:flex;align-items:center;gap:10px;}
.nav-logo img{height:32px;filter:brightness(0) invert(1);transition:filter .3s;}
nav.scrolled .nav-logo img{filter:none;}
.nav-logo-text{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.02em;transition:color .3s;}
nav.scrolled .nav-logo-text{color:#0A1628;}
.nav-links{display:flex;gap:4px;}
.nav-links a{
  font-size:14px;font-weight:600;color:rgba(255,255,255,.85);
  padding:7px 14px;border-radius:8px;transition:all .2s;
}
.nav-links a:hover{color:#fff;background:rgba(255,255,255,.15);}
nav.scrolled .nav-links a{color:#374151;}
nav.scrolled .nav-links a:hover{color:#0A1628;background:#F3F4F6;}
.nav-right{display:flex;align-items:center;gap:10px;}
.nav-login{
  font-size:14px;font-weight:600;color:rgba(255,255,255,.9);
  padding:8px 18px;border-radius:8px;border:1px solid rgba(255,255,255,.35);
  transition:all .2s;
}
.nav-login:hover{background:rgba(255,255,255,.15);color:#fff;}
nav.scrolled .nav-login{color:#374151;border-color:#E5E7EB;}
nav.scrolled .nav-login:hover{background:#F3F4F6;}
.nav-signup{
  font-size:14px;font-weight:700;color:#0A1628;
  background:#fff;padding:8px 20px;border-radius:8px;
  transition:transform .2s,box-shadow .2s;
}
.nav-signup:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(255,255,255,.4);}
nav.scrolled .nav-signup{background:#1A56DB;color:#fff;}
nav.scrolled .nav-signup:hover{box-shadow:0 4px 16px rgba(26,86,219,.35);}
.nav-user{display:flex;align-items:center;gap:14px;}
.nav-user span{font-size:14px;font-weight:500;color:rgba(255,255,255,.85);}
nav.scrolled .nav-user span{color:#374151;}
.nav-user a{font-size:14px;font-weight:700;color:#fff;}
nav.scrolled .nav-user a{color:#1A56DB;}

/* ── HERO ── */
.hero{
  position:relative;min-height:100vh;
  display:flex;align-items:center;
  overflow:hidden;
}
.hero-bg{
  position:absolute;inset:0;
  background-image:url('assets/images/country/bg.avif');
  background-size:cover;background-position:center;
  transform:scale(1.05);
}
.hero-bg.loaded{transform:scale(1);}
.hero-overlay{
  position:absolute;inset:0;
  background:linear-gradient(105deg,rgba(10,22,40,.82) 0%,rgba(10,22,40,.5) 55%,rgba(10,22,40,.2) 100%);
}
.hero-content{
  position:relative;z-index:2;
  max-width:1200px;margin:0 auto;padding:120px 40px 80px;
  display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;
}
.hero-left{}
.hero-badge{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(255,255,255,.15);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.25);
  color:#fff;font-size:12px;font-weight:700;
  padding:6px 14px;border-radius:99px;margin-bottom:24px;
  letter-spacing:.08em;text-transform:uppercase;
  animation:fadeIn .8s ease both;
}
.hero-badge-dot{width:7px;height:7px;border-radius:50%;background:#38BDF8;animation:pulse 2s infinite;}
.hero-h1{
  font-family:'Instrument Serif',serif;
  font-size:clamp(42px,5.5vw,72px);
  line-height:1.06;letter-spacing:-.02em;
  color:#fff;margin-bottom:20px;
  animation:slideLeft .9s ease .1s both;
}
.hero-h1 em{font-style:italic;color:#60A5FA;}
.hero-sub{
  font-size:17px;color:rgba(255,255,255,.75);
  line-height:1.7;margin-bottom:36px;max-width:46ch;
  animation:slideLeft .9s ease .2s both;
}
.hero-btns{
  display:flex;gap:12px;flex-wrap:wrap;
  animation:slideLeft .9s ease .3s both;
}
.hero-btn-primary{
  display:inline-flex;align-items:center;gap:8px;
  background:#1A56DB;color:#fff;
  font-size:15px;font-weight:700;
  padding:14px 28px;border-radius:10px;
  transition:transform .2s,box-shadow .2s,background .2s;
}
.hero-btn-primary:hover{background:#1E40AF;transform:translateY(-2px);box-shadow:0 8px 24px rgba(26,86,219,.4);}
.hero-btn-secondary{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(255,255,255,.15);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.35);color:#fff;
  font-size:15px;font-weight:700;
  padding:14px 28px;border-radius:10px;
  transition:background .2s,transform .2s;
}
.hero-btn-secondary:hover{background:rgba(255,255,255,.25);transform:translateY(-2px);}

/* Hero stats float card */
.hero-stats-card{
  background:rgba(255,255,255,.12);backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.2);
  border-radius:20px;padding:32px;
  animation:slideRight .9s ease .2s both;
}
.hero-stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.hs-item{
  background:rgba(255,255,255,.1);border-radius:14px;
  padding:20px;text-align:center;
  transition:background .2s;
}
.hs-item:hover{background:rgba(255,255,255,.18);}
.hs-num{
  font-size:34px;font-weight:800;color:#fff;
  letter-spacing:-.03em;line-height:1;margin-bottom:6px;
}
.hs-num span{color:#60A5FA;}
.hs-label{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.6);}
.hero-trust{
  margin-top:20px;padding-top:20px;
  border-top:1px solid rgba(255,255,255,.15);
  display:flex;align-items:center;gap:12px;
}
.trust-avatars{display:flex;}
.trust-avatars .av{
  width:32px;height:32px;border-radius:50%;
  border:2px solid rgba(255,255,255,.4);
  background:linear-gradient(135deg,#6366F1,#8B5CF6);
  display:flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;color:#fff;
  margin-left:-8px;
}
.trust-avatars .av:first-child{margin-left:0;}
.trust-text{font-size:13px;color:rgba(255,255,255,.75);}
.trust-text strong{color:#fff;font-weight:700;}

/* ── SEARCH BAR ── */
.search-section{
  background:#fff;
  box-shadow:0 8px 40px rgba(10,22,40,.12);
  border-radius:0 0 20px 20px;
  padding:28px 40px;
  position:relative;z-index:10;
  margin:0 40px;
  border:1px solid #E5E7EB;
  border-top:none;
}
.search-tabs{display:flex;gap:6px;margin-bottom:20px;}
.stab{
  font-size:14px;font-weight:600;color:#6B7280;
  padding:8px 18px;border-radius:8px;cursor:pointer;
  border:1px solid transparent;transition:all .2s;
  display:flex;align-items:center;gap:7px;
}
.stab.active{background:#EFF6FF;color:#1A56DB;border-color:#BFDBFE;}
.stab:hover:not(.active){background:#F9FAFB;color:#374151;}
.stab svg{width:16px;height:16px;}
.search-bar{
  display:flex;gap:0;
  background:#F9FAFB;border:1px solid #E5E7EB;
  border-radius:12px;overflow:hidden;
  transition:box-shadow .2s,border-color .2s;
}
.search-bar:focus-within{
  border-color:#93C5FD;
  box-shadow:0 0 0 4px rgba(147,197,253,.25);
}
.sb-field{
  flex:1;padding:0 20px;
  display:flex;flex-direction:column;justify-content:center;
  border-right:1px solid #E5E7EB;min-height:64px;
  background:transparent;
}
.sb-field:last-of-type{border-right:none;}
.sb-label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9CA3AF;margin-bottom:3px;}
.sb-field input,.sb-field select{
  border:none;background:transparent;outline:none;
  font-family:'Plus Jakarta Sans',sans-serif;
  font-size:14px;font-weight:600;color:#0A1628;
}
.sb-field input::placeholder{color:#9CA3AF;font-weight:400;}
.sb-field select{cursor:pointer;color:#0A1628;}
.search-btn{
  background:#1A56DB;color:#fff;border:none;
  padding:0 32px;cursor:pointer;
  display:flex;align-items:center;gap:10px;
  font-family:'Plus Jakarta Sans',sans-serif;
  font-size:15px;font-weight:700;
  transition:background .2s;flex-shrink:0;
}
.search-btn:hover{background:#1E40AF;}
.search-btn svg{width:20px;height:20px;}

/* ── FEATURES BAR ── */
.features-bar{
  background:#F0F7FF;
  border-bottom:1px solid #DBEAFE;
  padding:24px 40px;
}
.features-inner{
  max-width:1200px;margin:0 auto;
  display:grid;grid-template-columns:repeat(4,1fr);gap:20px;
}
.feat-item{display:flex;align-items:center;gap:14px;}
.feat-icon{
  width:44px;height:44px;border-radius:12px;
  background:#DBEAFE;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-size:20px;
}
.feat-text h4{font-size:14px;font-weight:700;color:#0A1628;margin-bottom:2px;}
.feat-text p{font-size:12px;color:#6B7280;line-height:1.4;}

/* ── SECTIONS ── */
.section{padding:88px 40px;}
.s-wrap{max-width:1200px;margin:0 auto;}
.s-head{margin-bottom:48px;}
.s-tag{
  display:inline-block;font-size:12px;font-weight:700;
  letter-spacing:.1em;text-transform:uppercase;
  color:#1A56DB;margin-bottom:12px;
}
.s-title{
  font-family:'Instrument Serif',serif;
  font-size:clamp(28px,3.5vw,44px);
  font-weight:400;letter-spacing:-.02em;color:#0A1628;
  line-height:1.12;margin-bottom:10px;
}
.s-sub{font-size:16px;color:#6B7280;max-width:54ch;line-height:1.65;}
.s-row{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;}
.view-all{
  display:inline-flex;align-items:center;gap:6px;
  font-size:14px;font-weight:700;color:#1A56DB;
  border:1px solid #BFDBFE;padding:10px 20px;border-radius:8px;
  transition:all .2s;flex-shrink:0;
}
.view-all:hover{background:#EFF6FF;transform:translateX(2px);}

/* ── COUNTRY CARDS ── */
.countries-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.country-card{
  border-radius:20px;overflow:hidden;
  position:relative;height:340px;
  display:flex;flex-direction:column;justify-content:flex-end;
  transition:transform .35s ease,box-shadow .35s ease;
  cursor:pointer;
}
.country-card:hover{transform:translateY(-6px);box-shadow:0 24px 48px rgba(10,22,40,.18);}
.cc-img{
  position:absolute;inset:0;
  background-size:cover;background-position:center;
  transition:transform .5s ease;
}
.country-card:hover .cc-img{transform:scale(1.06);}
.cc-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(10,22,40,.85) 0%,rgba(10,22,40,.3) 50%,transparent 100%);
}
.cc-badge{
  position:absolute;top:18px;left:18px;
  background:rgba(255,255,255,.2);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.3);
  font-size:11px;font-weight:700;letter-spacing:.08em;
  text-transform:uppercase;color:#fff;
  padding:5px 12px;border-radius:99px;
}
.cc-body{position:relative;z-index:2;padding:24px;}
.cc-flag{font-size:28px;margin-bottom:8px;}
.cc-name{
  font-family:'Instrument Serif',serif;
  font-size:26px;color:#fff;margin-bottom:4px;letter-spacing:-.01em;
}
.cc-currency{font-size:12px;font-weight:600;color:rgba(255,255,255,.7);letter-spacing:.06em;text-transform:uppercase;margin-bottom:12px;}
.cc-row{display:flex;align-items:center;justify-content:space-between;}
.cc-count{font-size:13px;color:rgba(255,255,255,.8);}
.cc-arrow{
  width:36px;height:36px;border-radius:50%;
  background:rgba(255,255,255,.2);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.3);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:16px;
  transition:background .2s;
}
.country-card:hover .cc-arrow{background:#1A56DB;border-color:#1A56DB;}

/* ── UNIVERSITY CARDS ── */
.uni-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.uni-card{
  border-radius:20px;overflow:hidden;
  border:1px solid #E5E7EB;background:#fff;
  transition:transform .3s ease,box-shadow .3s ease;
}
.uni-card:hover{transform:translateY(-6px);box-shadow:0 20px 48px rgba(10,22,40,.12);}
.uc-img{
  height:180px;overflow:hidden;position:relative;
}
.uc-img img{
  width:100%;height:100%;object-fit:cover;
  transition:transform .5s ease;
}
.uni-card:hover .uc-img img{transform:scale(1.06);}
.uc-badge{
  position:absolute;top:14px;left:14px;
  font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  padding:4px 10px;border-radius:99px;
  background:rgba(255,255,255,.92);color:#0A1628;
}
.uc-body{padding:20px;}
.uc-country{
  font-size:11px;font-weight:700;letter-spacing:.08em;
  text-transform:uppercase;color:#6B7280;margin-bottom:7px;
  display:flex;align-items:center;gap:5px;
}
.uc-name{
  font-size:16px;font-weight:700;color:#0A1628;
  line-height:1.3;margin-bottom:4px;
}
.uc-city{font-size:13px;color:#9CA3AF;margin-bottom:14px;}
.uc-footer{
  display:flex;align-items:center;justify-content:space-between;
  padding-top:14px;border-top:1px solid #F3F4F6;
}
.uc-price{font-size:12px;color:#6B7280;}
.uc-price strong{font-size:16px;font-weight:800;color:#1A56DB;display:block;}
.uc-stars{display:flex;align-items:center;gap:3px;font-size:13px;}
.star-on{color:#FBBF24;}
.star-off{color:#E5E7EB;}
.uc-btn{
  background:#EFF6FF;color:#1A56DB;
  font-size:13px;font-weight:700;
  padding:8px 16px;border-radius:8px;
  transition:background .2s,color .2s;
}
.uc-btn:hover{background:#1A56DB;color:#fff;}

/* ── WHY VEYA + CTA SPLIT ── */
.split-section{
  background:#F8FAFF;
  border-top:1px solid #E5E7EB;border-bottom:1px solid #E5E7EB;
  padding:88px 40px;
}
.split-inner{
  max-width:1200px;margin:0 auto;
  display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;
}
.why-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:36px;}
.why-item{
  background:#fff;border:1px solid #E5E7EB;
  border-radius:16px;padding:22px;
  transition:box-shadow .2s,transform .2s;
}
.why-item:hover{box-shadow:0 8px 24px rgba(10,22,40,.08);transform:translateY(-2px);}
.why-icon{font-size:26px;margin-bottom:12px;}
.why-item h4{font-size:15px;font-weight:700;color:#0A1628;margin-bottom:6px;}
.why-item p{font-size:13px;color:#6B7280;line-height:1.55;}
.cta-card{
  border-radius:24px;overflow:hidden;
  position:relative;height:100%;min-height:420px;
  display:flex;flex-direction:column;justify-content:flex-end;
}
.cta-card-bg{
  position:absolute;inset:0;
  background-image:url('assets/images/country/bgcard.jpg');
  background-size:cover;background-position:center;
  transition:transform .5s ease;
}
.cta-card:hover .cta-card-bg{transform:scale(1.04);}
.cta-card-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(10,22,40,.9) 0%,rgba(10,22,40,.4) 60%,transparent 100%);
}
.cta-card-body{position:relative;z-index:2;padding:32px;}
.cta-tag{
  font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:#60A5FA;margin-bottom:14px;
  display:flex;align-items:center;gap:6px;
}
.cta-card h3{
  font-family:'Instrument Serif',serif;
  font-size:32px;color:#fff;line-height:1.15;margin-bottom:10px;
}
.cta-card p{font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;margin-bottom:24px;}
.cta-card-btn{
  display:inline-flex;align-items:center;gap:8px;
  background:#fff;color:#0A1628;
  font-size:14px;font-weight:700;
  padding:12px 24px;border-radius:10px;
  transition:transform .2s,box-shadow .2s;
}
.cta-card-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(255,255,255,.3);}

/* ── REVIEWS ── */
.reviews-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.rv-card{
  background:#fff;border:1px solid #E5E7EB;
  border-radius:20px;padding:28px;
  transition:box-shadow .3s,transform .3s;
}
.rv-card:hover{box-shadow:0 12px 32px rgba(10,22,40,.08);transform:translateY(-4px);}
.rv-quote{font-size:32px;line-height:1;color:#DBEAFE;margin-bottom:16px;}
.rv-stars{display:flex;gap:3px;margin-bottom:14px;}
.rv-title{font-size:16px;font-weight:700;color:#0A1628;margin-bottom:10px;line-height:1.35;}
.rv-comment{
  font-size:14px;color:#6B7280;line-height:1.65;
  display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;
  margin-bottom:22px;flex:1;
}
.rv-author{display:flex;align-items:center;gap:12px;padding-top:18px;border-top:1px solid #F3F4F6;}
.rv-av{
  width:38px;height:38px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;font-weight:800;color:#fff;
}
.rv-name{font-size:14px;font-weight:700;color:#0A1628;}
.rv-uni{font-size:12px;color:#9CA3AF;margin-top:1px;}
.rv-empty{
  grid-column:1/-1;text-align:center;
  padding:64px;color:#D1D5DB;font-size:14px;
}
.rv-empty a{color:#1A56DB;font-weight:700;}

/* ── NEWSLETTER ── */


/* ── FOOTER ── */
footer{
  background:#060E1E;padding:32px 40px;
  border-top:1px solid rgba(255,255,255,.05);
}
.footer-inner{
  max-width:1200px;margin:0 auto;
  display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;
}
.footer-logo{display:flex;align-items:center;gap:8px;}
.footer-logo img{height:24px;filter:brightness(0) invert(1);opacity:.6;}
.footer-logo span{font-size:16px;font-weight:800;color:rgba(255,255,255,.6);}
.footer-links{display:flex;gap:24px;}
.footer-links a{font-size:13px;color:rgba(255,255,255,.4);transition:color .2s;font-weight:500;}
.footer-links a:hover{color:rgba(255,255,255,.8);}
.footer-copy{font-size:12px;color:rgba(255,255,255,.3);}

/* ── RESPONSIVE ── */
@media(max-width:1024px){
  .hero-content{grid-template-columns:1fr;gap:40px;}
  .hero-stats-card{display:none;}
  .split-inner{grid-template-columns:1fr;gap:40px;}
}
@media(max-width:900px){
  .countries-grid,.uni-grid,.reviews-grid{grid-template-columns:1fr 1fr;}
  .search-bar{flex-wrap:wrap;}
  .features-inner{grid-template-columns:1fr 1fr;}
}
@media(max-width:640px){
  nav{padding:0 20px;}
  .nav-links{display:none;}
  .hero-content,.section,.split-section,.search-section{padding-left:20px;padding-right:20px;}
  .countries-grid,.uni-grid,.reviews-grid{grid-template-columns:1fr;}
  .features-inner{grid-template-columns:1fr 1fr;}
  .search-section{margin:0;}
  .why-grid{grid-template-columns:1fr;}
  
  .footer-inner{flex-direction:column;align-items:flex-start;gap:12px;}
}
@media(prefers-reduced-motion:reduce){
  .reveal,.reveal-left,.reveal-right,.reveal-scale{opacity:1;transform:none;transition:none;}
  .hero-bg{transform:none!important;transition:none!important;}
  * {animation-duration:.01ms!important;transition-duration:.01ms!important;}
}
</style>
</head>
<body>

<!-- NAV -->
<?php $nav_active = 'home'; include 'includes/nav.php'; ?>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg" id="heroBg"></div>
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="hero-left">
      <div class="hero-badge">
        <span class="hero-badge-dot"></span>
        Explore · Dream · Discover
      </div>
      <h1 class="hero-h1">Find Your University<br><em>Abroad</em> With Ease</h1>
      <p class="hero-sub">Compare programs, tuition fees in your own currency, and real student reviews from Thailand, Singapore, and Malaysia — all in one place.</p>
      <div class="hero-btns">
        <a href="universities.php" class="hero-btn-primary">Explore Universities →</a>
        <a href="scholarships.php" class="hero-btn-secondary">View Scholarships</a>
      </div>
    </div>
    <div>
      <div class="hero-stats-card">
        <div class="hero-stats-grid">
          <div class="hs-item">
            <div class="hs-num" data-count="<?=$stat_universities?>"><?=$stat_universities?><span>+</span></div>
            <div class="hs-label">Universities</div>
          </div>
          <div class="hs-item">
            <div class="hs-num" data-count="<?=$stat_programs?>"><?=$stat_programs?><span>+</span></div>
            <div class="hs-label">Programs</div>
          </div>
          <div class="hs-item">
            <div class="hs-num" data-count="<?=$stat_scholarships?>"><?=$stat_scholarships?><span>+</span></div>
            <div class="hs-label">Scholarships</div>
          </div>
          <div class="hs-item">
            <div class="hs-num" data-count="<?=$stat_reviews?>"><?=$stat_reviews?><span>+</span></div>
            <div class="hs-label">Reviews</div>
          </div>
        </div>
        <div class="hero-trust">
          <div class="trust-avatars">
            <div class="av">T</div>
            <div class="av" style="background:linear-gradient(135deg,#059669,#10B981)">S</div>
            <div class="av" style="background:linear-gradient(135deg,#D97706,#F59E0B)">A</div>
            <div class="av" style="background:linear-gradient(135deg,#DC2626,#EF4444)">K</div>
          </div>
          <div class="trust-text">Trusted by <strong>500+ Myanmar students</strong> planning to study abroad</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SEARCH BAR -->
<div class="search-section reveal">
  <div class="search-tabs">
    <div class="stab active" data-dest="universities.php#results" data-type="universities">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18"/></svg>
      Universities
    </div>
    <div class="stab" data-dest="scholarships.php" data-type="scholarships">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Scholarships
    </div>
  </div>
  <form method="GET" action="universities.php#results" id="heroSearchForm">
    <input type="hidden" name="search_type" id="searchTypeInput" value="universities">
    <div class="search-bar">
      <div class="sb-field">
        <div class="sb-label">Country</div>
        <select name="countries[]">
          <option value="">All Countries</option>
          <?php $countries_result->data_seek(0); while($c=$countries_result->fetch_assoc()): ?>
            <option value="<?=$c['country_id']?>"><?=htmlspecialchars($c['country_name'])?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="sb-field" style="flex:2">
        <div class="sb-label">Search</div>
        <input type="text" name="q" placeholder="University name, scholarship, city…">
      </div>
      <div class="sb-field">
        <div class="sb-label">Degree Level</div>
        <select name="level">
          <option value="">Any Level</option>
          <option>Bachelor</option>
          <option>Master</option>
          <option>PhD</option>
          <option>Diploma</option>
        </select>
      </div>
      <button class="search-btn" type="submit">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        Search
      </button>
    </div>
  </form>
</div>

<!-- FEATURES BAR -->
<div class="features-bar reveal">
  <div class="features-inner">
    <div class="feat-item">
      <div class="feat-icon">🎯</div>
      <div class="feat-text"><h4>Accurate Information</h4><p>Verified university data updated regularly</p></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon">💱</div>
      <div class="feat-text"><h4>Multi-Currency Fees</h4><p>See fees in MMK, THB, SGD, MYR and more</p></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon">⭐</div>
      <div class="feat-text"><h4>Real Student Reviews</h4><p>Honest reviews from Myanmar students abroad</p></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon">🎓</div>
      <div class="feat-text"><h4>Scholarship Finder</h4><p>Discover funding opportunities for your program</p></div>
    </div>
  </div>
</div>

<!-- POPULAR DESTINATIONS -->
<section class="section">
  <div class="s-wrap">
    <div class="s-head s-row reveal">
      <div>
        <div class="s-tag">Popular Destinations</div>
        <h2 class="s-title">Where will you study?</h2>
        <p class="s-sub">Explore universities in Southeast Asia's top study destinations.</p>
      </div>
      <a href="universities.php" class="view-all">View All →</a>
    </div>
    <div class="countries-grid">
      <?php
      $country_badges=['TH'=>'Most Popular','SG'=>'Top Ranked','MY'=>'Best Value'];
      $countries_result->data_seek(0);$ci=0;
      while($c=$countries_result->fetch_assoc()):
        $code=$c['country_code'];
        $photo=$country_photos[$code]??'';
        $badge=$country_badges[$code]??'';
      ?>
      <a href="universities.php?countries[]=<?=$c['country_id']?>" class="country-card reveal stagger-<?=($ci+1)?>">
        <div class="cc-img" style="background-image:url('<?=$photo?>')"></div>
        <div class="cc-overlay"></div>
        <?php if($badge): ?><div class="cc-badge"><?=$badge?></div><?php endif; ?>
        <div class="cc-body">
          <div class="cc-flag"><?=$flags[$code]??'🏫'?></div>
          <div class="cc-name"><?=htmlspecialchars($c['country_name'])?></div>
          <div class="cc-currency"><?=htmlspecialchars($c['currency_code'])?> · <?=htmlspecialchars($c['country_code'])?></div>
          <div class="cc-row">
            <div>
              <div class="cc-count"><?=$c['uni_count']>0?$c['uni_count'].' universities':'Coming soon'?></div>
            </div>
            <div class="cc-arrow">→</div>
          </div>
        </div>
      </a>
      <?php $ci++;endwhile; ?>
    </div>
  </div>
</section>

<!-- FEATURED UNIVERSITIES -->
<section class="section" style="background:#F8FAFF;border-top:1px solid #E5E7EB;border-bottom:1px solid #E5E7EB;">
  <div class="s-wrap">
    <div class="s-head s-row reveal">
      <div>
        <div class="s-tag">Featured Universities</div>
        <h2 class="s-title">Recently added universities</h2>
      </div>
      <a href="universities.php" class="view-all">View All →</a>
    </div>
    <div class="uni-grid">
      <?php if($featured->num_rows>0):
        $pi=0;
        while($u=$featured->fetch_assoc()):
          $photo = !empty($u['image_url']) ? $u['image_url'] : $fallback_photo;
          $pi++;
          $rating=$u['avg_rating']?round($u['avg_rating'],1):null;
          $words=array_filter(explode(' ',$u['name']),fn($w)=>strlen($w)>2);
      ?>
      <div class="uni-card reveal stagger-<?=($pi<=3?$pi:$pi-3)?>">
        <div class="uc-img">
          <img src="<?=$photo?>" alt="<?=htmlspecialchars($u['name'])?>">
          <span class="uc-badge"><?=htmlspecialchars($u['university_type'])?></span>
        </div>
        <div class="uc-body">
          <div class="uc-country"><?=($flags[$u['country_code']]??'').' '.htmlspecialchars($u['country_name'])?></div>
          <div class="uc-name"><?=htmlspecialchars($u['name'])?></div>
          <div class="uc-city">📍 <?=htmlspecialchars($u['city']??'—')?></div>
          <div class="uc-footer">
            <div class="uc-price">
              <span>Tuition from</span>
              <strong><?=htmlspecialchars($u['currency_code'])?></strong>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="uc-stars">
                <?php $filled=$rating?round($rating):0; for($i=1;$i<=5;$i++) echo '<span class="'.($i<=$filled?'star-on':'star-off').'">★</span>'; ?>
              </div>
              <a href="university_detail.php?id=<?=$u['university_id']?>" class="uc-btn">View →</a>
            </div>
          </div>
        </div>
      </div>
      <?php endwhile;else: ?>
        <div class="uni-empty" style="grid-column:1/-1;text-align:center;padding:60px;color:#9CA3AF;">No universities listed yet.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- WHY VEYA + CTA CARD -->
<section class="split-section">
  <div class="split-inner">
    <div class="reveal-left">
      <div class="s-tag">Why Choose Veya?</div>
      <h2 class="s-title">Everything you need to study abroad</h2>
      <p class="s-sub">From finding the right university to understanding costs in your own currency — Veya makes the whole journey simple.</p>
      <div class="why-grid">
        <div class="why-item">
          <div class="why-icon">🌏</div>
          <h4>Wide Range of Choices</h4>
          <p>Universities from Thailand, Singapore and Malaysia all in one platform.</p>
        </div>
        <div class="why-item">
          <div class="why-icon">👍</div>
          <h4>Trusted by Students</h4>
          <p>Real reviews and experiences from students who studied abroad.</p>
        </div>
        <div class="why-item">
          <div class="why-icon">💻</div>
          <h4>Easy to Use</h4>
          <p>Search, compare and shortlist universities in just a few clicks.</p>
        </div>
        <div class="why-item">
          <div class="why-icon">🏷️</div>
          <h4>Transparent Fees</h4>
          <p>See exact tuition fees converted into MMK or your preferred currency.</p>
        </div>
      </div>
    </div>
    <div class="reveal-right">
      <div class="cta-card">
        <div class="cta-card-bg"></div>
        <div class="cta-card-overlay"></div>
        <div class="cta-card-body">
          <div class="cta-tag">✈️ Let's Go!</div>
          <h3>Your Next<br>Adventure Awaits!</h3>
          <p>Discover breathtaking universities and create an unforgettable academic journey abroad.</p>
          <a href="register.php" class="cta-card-btn">Plan Your Study Trip →</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- STUDENT REVIEWS -->
<section class="section">
  <div class="s-wrap">
    <div class="s-head reveal">
      <div class="s-tag">Student Reviews</div>
      <h2 class="s-title">What students say about studying abroad</h2>
      <p class="s-sub">Real experiences from students who chose Veya to find their university.</p>
    </div>
    <div class="reviews-grid">
      <?php
      $av_colors=['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#059669,#10B981)','linear-gradient(135deg,#D97706,#F59E0B)'];
      if($recent_reviews->num_rows>0):$ri=0;while($rev=$recent_reviews->fetch_assoc()):
        $initial=strtoupper($rev['reviewer'][0]??'U');
      ?>
      <div class="rv-card reveal stagger-<?=($ri+1)?>">
        <div class="rv-quote">"</div>
        <div class="rv-stars">
          <?php for($i=1;$i<=5;$i++) echo '<span class="'.($i<=$rev['rating']?'star-on':'star-off').'" style="font-size:16px;">★</span>'; ?>
        </div>
        <?php if($rev['title']): ?><div class="rv-title"><?=htmlspecialchars($rev['title'])?></div><?php endif; ?>
        <div class="rv-comment"><?=htmlspecialchars($rev['comment']??'')?></div>
        <div class="rv-author">
          <div class="rv-av" style="background:<?=$av_colors[$ri%3]?>"><?=$initial?></div>
          <div>
            <div class="rv-name"><?=htmlspecialchars($rev['reviewer'])?></div>
            <div class="rv-uni"><?=htmlspecialchars(mb_strimwidth($rev['university_name'],0,30,'…'))?></div>
          </div>
        </div>
      </div>
      <?php $ri++;endwhile;else: ?>
        <div class="rv-empty">No reviews yet. <a href="register.php">Be the first →</a></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FOOTER -->

<?php include 'includes/footer.php'; ?>


<script>
// ── Hero BG load animation
const heroBg = document.getElementById('heroBg');
const img = new Image();
img.onload = () => heroBg.classList.add('loaded');
img.src = heroBg.style.backgroundImage.slice(5,-2) || '';
setTimeout(()=>heroBg.classList.add('loaded'), 200);

// ── Sticky nav on scroll
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', ()=>{
  navbar.classList.toggle('scrolled', window.scrollY > 60);
}, {passive:true});

// ── Intersection Observer for reveal animations
const observer = new IntersectionObserver((entries)=>{
  entries.forEach(e=>{
    if(e.isIntersecting){
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
},{threshold:0.12,rootMargin:'0px 0px -40px 0px'});

document.querySelectorAll('.reveal,.reveal-left,.reveal-right,.reveal-scale').forEach(el=>observer.observe(el));

// ── Search tab switching — routes to correct page
const heroForm = document.getElementById('heroSearchForm');
document.querySelectorAll('.stab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.stab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const dest = tab.dataset.dest || 'universities.php#results';
    const type = tab.dataset.type || 'universities';
    heroForm.action = dest;
    document.getElementById('searchTypeInput').value = type;
    // Scholarships page doesn't use 'level' filter
    const levelField = heroForm.querySelector('[name="level"]')?.closest('.sb-field');
    if (levelField) levelField.style.display = type === 'scholarships' ? 'none' : '';
  });
});

// ── Smooth parallax on hero
window.addEventListener('scroll', ()=>{
  const s = window.scrollY;
  if(s < window.innerHeight) heroBg.style.transform = `scale(1) translateY(${s * 0.3}px)`;
}, {passive:true});

// ── Animated stat counters
function animateCounter(el) {
  const target = parseInt(el.dataset.count) || 0;
  const duration = 1400;
  const start = performance.now();
  const update = (now) => {
    const p = Math.min((now - start)/duration, 1);
    const ease = 1 - Math.pow(1-p, 3);
    el.childNodes[0].textContent = Math.round(target * ease);
    if(p < 1) requestAnimationFrame(update);
    else el.childNodes[0].textContent = target;
  };
  requestAnimationFrame(update);
}
const counterObserver = new IntersectionObserver((entries)=>{
  entries.forEach(e=>{
    if(e.isIntersecting){ animateCounter(e.target); counterObserver.unobserve(e.target); }
  });
},{threshold:0.5});
document.querySelectorAll('.hs-num[data-count]').forEach(el=>counterObserver.observe(el));

// ── Card hover tilt effect
document.querySelectorAll('.country-card,.uni-card').forEach(card=>{
  card.addEventListener('mousemove', e=>{
    const r = card.getBoundingClientRect();
    const x = (e.clientX - r.left)/r.width - 0.5;
    const y = (e.clientY - r.top)/r.height - 0.5;
    card.style.transform = `translateY(-6px) rotateY(${x*5}deg) rotateX(${-y*5}deg)`;
  });
  card.addEventListener('mouseleave', ()=>{
    card.style.transform = '';
    card.style.transition = 'transform .4s ease';
  });
  card.addEventListener('mouseenter', ()=>{
    card.style.transition = 'transform .1s ease';
  });
});
</script>
</body>
</html>