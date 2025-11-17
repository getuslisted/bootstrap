(function (global) {
  const feedGroups = {
    home: {
      mainstream: {
        label: 'Mainstream',
        description:
          'Stay informed with mainstream conservative news coverage, campaign analysis, and Capitol Hill updates aggregated from the top right-of-center outlets.',
        feeds: [
          { id: 'breitbart', name: 'Breitbart', url: 'https://feeds.feedburner.com/BreitbartFeed', homepage: 'https://www.breitbart.com/' },
          { id: 'foxnews', name: 'Fox News', url: 'https://feeds.foxnews.com/foxnews/most-popular', homepage: 'https://www.foxnews.com/' },
          { id: 'dailywire', name: 'Daily Wire', url: 'https://www.dailywire.com/rss', homepage: 'https://www.dailywire.com/' },
          { id: 'newsmax', name: 'Newsmax', url: 'https://www.newsmax.com/rss/all/', homepage: 'https://www.newsmax.com/' },
          { id: 'washingtontimes', name: 'Washington Times', url: 'https://www.washingtontimes.com/rss/headlines/news/politics/', homepage: 'https://www.washingtontimes.com/' },
          { id: 'epochtimes', name: 'Epoch Times', url: 'https://www.theepochtimes.com/feed/', homepage: 'https://www.theepochtimes.com/' },
          { id: 'wnd', name: 'WND', url: 'https://rss.wnd.com/wnd_news', homepage: 'https://www.wnd.com/' },
          { id: 'gatewaypundit', name: 'Gateway Pundit', url: 'https://www.thegatewaypundit.com/feed/', homepage: 'https://www.thegatewaypundit.com/' },
          { id: 'dailycaller', name: 'Daily Caller', url: 'https://www.dailycaller.com/feed/', homepage: 'https://www.dailycaller.com/' },
          { id: 'westernjournal', name: 'Western Journal', url: 'https://www.westernjournal.com/feed/', homepage: 'https://www.westernjournal.com/' },
          { id: 'examiner', name: 'Washington Examiner', url: 'https://www.washingtonexaminer.com/rss', homepage: 'https://www.washingtonexaminer.com/' },
          { id: 'nationalreview', name: 'National Review', url: 'https://www.nationalreview.com/feed/', homepage: 'https://www.nationalreview.com/' },
          { id: 'federalist', name: 'The Federalist', url: 'https://www.thefederalist.com/feed/', homepage: 'https://thefederalist.com/' },
          { id: 'hotair', name: 'Hot Air', url: 'https://hotair.com/feed/', homepage: 'https://hotair.com/' },
          { id: 'redstate', name: 'RedState', url: 'https://redstate.com/feed', homepage: 'https://redstate.com/' },
          { id: 'townhall', name: 'Townhall', url: 'https://www.townhall.com/rss/all', homepage: 'https://townhall.com/' },
          { id: 'pjmedia', name: 'PJ Media', url: 'https://www.pjmedia.com/feed/', homepage: 'https://pjmedia.com/' },
          { id: 'twitchy', name: 'Twitchy', url: 'https://twitchy.com/feed/', homepage: 'https://twitchy.com/' },
          { id: 'spectatorworld', name: 'Spectator World', url: 'https://thespectator.com/feed/', homepage: 'https://thespectator.com/' },
          { id: 'spectator', name: 'The American Spectator', url: 'https://spectator.org/feed/', homepage: 'https://spectator.org/' }
        ]
      },
      independent: {
        label: 'Independent Media',
        description:
          'Explore independent conservative commentary, investigative reports, and grassroots perspectives curated into one liberty-first news digest.',
        feeds: [
          { id: 'blaze', name: 'The Blaze', url: 'https://www.theblaze.com/rss', homepage: 'https://www.theblaze.com/' },
          { id: 'collegefix', name: 'College Fix', url: 'https://www.thecollegefix.com/feed/', homepage: 'https://www.thecollegefix.com/' },
          { id: 'humanevents', name: 'Human Events', url: 'https://www.humanevents.com/feed/', homepage: 'https://humanevents.com/' },
          { id: 'americanthinker', name: 'American Thinker', url: 'https://www.americanthinker.com/blog/rss.xml', homepage: 'https://www.americanthinker.com/' },
          { id: 'conservativereview', name: 'Conservative Review', url: 'http://feeds.feedburner.com/ConservativeReview', homepage: 'https://www.conservativereview.com/' },
          { id: 'powerline', name: 'Power Line', url: 'https://www.powerlineblog.com/feed/', homepage: 'https://www.powerlineblog.com/' },
          { id: 'americangreatness', name: 'American Greatness', url: 'https://www.americangreatness.com/feed/', homepage: 'https://www.americangreatness.com/' },
          { id: 'bulwark', name: 'The Bulwark', url: 'https://thebulwark.com/feed/', homepage: 'https://thebulwark.com/' },
          { id: 'reason', name: 'Reason', url: 'https://www.reason.com/feed/', homepage: 'https://reason.com/' },
          { id: 'heritage', name: 'Heritage Foundation', url: 'https://www.heritage.org/rss/news', homepage: 'https://www.heritage.org/' },
          { id: 'dailysignal', name: 'Daily Signal', url: 'https://dailysignal.com/feed/', homepage: 'https://www.dailysignal.com/' },
          { id: 'frontpagemag', name: 'FrontPage Magazine', url: 'https://www.frontpagemag.com/feed', homepage: 'https://www.frontpagemag.com/' },
          { id: 'ijr', name: 'IJR', url: 'https://www.ijr.com/feed/', homepage: 'https://ijr.com/' },
          { id: 'lifesitenews', name: 'LifeSiteNews', url: 'https://www.lifesitenews.com/feed/', homepage: 'https://www.lifesitenews.com/' },
          { id: 'citizenfreepress', name: 'Citizen Free Press', url: 'https://www.citizenfreepress.com/feed/', homepage: 'https://citizenfreepress.com/' },
          { id: 'newsbusters', name: 'NewsBusters', url: 'https://www.newsbusters.org/feed', homepage: 'https://www.newsbusters.org/' },
          { id: 'mrc', name: 'Media Research Center', url: 'https://www.mediaresearchcenter.org/rss', homepage: 'https://www.mrc.org/' },
          { id: 'newboston', name: 'New Boston Post', url: 'https://www.newbostonpost.com/feed/', homepage: 'https://newbostonpost.com/' },
          { id: 'oann', name: 'OANN', url: 'https://www.oann.com/feed/', homepage: 'https://www.oann.com/' },
          { id: 'foxbusiness', name: 'Fox Business', url: 'https://www.foxbusiness.com/feed/', homepage: 'https://www.foxbusiness.com/' },
          { id: 'americanconservative', name: 'American Conservative', url: 'https://www.theamericanconservative.com/feed/', homepage: 'https://www.theamericanconservative.com/' }
        ]
      },
      christian: {
        label: 'Christian Media',
        description:
          'Follow Christian media ministries delivering faith-based news, religious liberty reporting, and pro-life coverage compiled for believers and families.',
        feeds: [
          { id: 'christianpost', name: 'Christian Post', url: 'https://www.christianpost.com/rss-feed.html', homepage: 'https://www.christianpost.com/' },
          { id: 'cbn', name: 'CBN News', url: 'https://www1.cbn.com/app_feeds/rss/cbn_news_breaking_news.xml', homepage: 'https://www1.cbn.com/' },
          { id: 'christiantoday', name: 'Christian Today', url: 'https://www.christiantoday.com/latestarticles.rss', homepage: 'https://www.christiantoday.com/' },
          { id: 'world', name: 'WORLD News Group', url: 'https://wng.org/rss/all-content', homepage: 'https://wng.org/' },
          { id: 'cna', name: 'Catholic News Agency', url: 'https://www.catholicnewsagency.com/rss/news/all', homepage: 'https://www.catholicnewsagency.com/' },
          { id: 'conservativehome', name: 'ConservativeHome USA', url: 'https://feeds.feedburner.com/conservativehomeusa', homepage: 'https://www.conservativehomeusa.org/' },
          { id: 'libertydaily', name: 'The Liberty Daily', url: 'https://www.thelibertydaily.com/feed/', homepage: 'https://www.thelibertydaily.com/' }
        ]
      }
    },
    videos: {
      mainstream: {
        label: 'Mainstream',
        description:
          'Stream mainstream conservative video briefings, breaking reports, and interviews from major cable and digital broadcasters in one playlist.',
        feeds: [
          { id: 'yt-breitbart', name: 'Breitbart', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCF5H4GCP_n_4i20sN_aNl_g', kind: 'video' },
          { id: 'yt-foxnews', name: 'Fox News', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCXIJgqnII2ZOINSWNOGFThA', kind: 'video' },
          { id: 'yt-dailywire', name: 'The Daily Wire', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCaeU6VvWcR5Kz1n1LwzQ5DQ', kind: 'video' },
          { id: 'yt-newsmax', name: 'Newsmax', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCs6EHzfVb_A3z3oE3K_X08g', kind: 'video' },
          { id: 'yt-washingtontimes', name: 'The Washington Times', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC6d69tD_5wH7J5Bv3A_44gA', kind: 'video' },
          { id: 'yt-epochtimes', name: 'The Epoch Times', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCn-yMQeC5m23r_uX9c3fWpA', kind: 'video' },
          { id: 'yt-wnd', name: 'WND (WorldNetDaily)', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCV10qNn97Xy-8u4t1XW2z9A', kind: 'video' },
          { id: 'yt-gateway', name: 'The Gateway Pundit', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCT-h4c1TzQj_7S6yq2yK57w', kind: 'video' },
          { id: 'yt-dailycaller', name: 'The Daily Caller', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCF-m8gXGvRKYX5Nf322h3lQ', kind: 'video' },
          { id: 'yt-westernjournal', name: 'The Western Journal', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCwE6k4m2rVwL4k7zP82y44Q', kind: 'video' },
          { id: 'yt-examiner', name: 'Washington Examiner', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCvXGz_q-iTjFv5P49Uv060Q', kind: 'video' },
          { id: 'yt-nationalreview', name: 'National Review', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCJ9XFf1fA-r8n29i9-u4X1g', kind: 'video' },
          { id: 'yt-federalist', name: 'The Federalist', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCs-tT-F2iK4529V961_Y19A', kind: 'video' },
          { id: 'yt-hotair', name: 'Hot Air', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCx8qJpGjB-E1xMh_G3n6qYQ', kind: 'video' },
          { id: 'yt-redstate', name: 'RedState', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCg8r-24J5-5u3nE0YqS48vQ', kind: 'video' },
          { id: 'yt-townhall', name: 'Townhall', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCn82B9iG4-106xQ6V-lY5_A', kind: 'video' },
          { id: 'yt-pjmedia', name: 'PJ Media', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC-S2V-lP26n6hY95eK3D94Q', kind: 'video' },
          { id: 'yt-twitchy', name: 'Twitchy', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCGgC4v18h07e0wW9J6YpWdg', kind: 'video' },
          { id: 'yt-spectatorworld', name: 'The Spectator World', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC3QW-3YxI3oB7b9h8H_lSng', kind: 'video' },
          { id: 'yt-blaze', name: 'The Blaze TV', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCzLqS9-JtLgB_w8Jk_mS0-w', kind: 'video' },
          { id: 'yt-oan', name: 'OAN (One America News)', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC3z9b6Kk-tJd_Z9gWzK2V8A', kind: 'video' },
          { id: 'yt-foxbusiness', name: 'Fox Business', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCxliNnriN-xR3qgQ8_gI-iw', kind: 'video' }
        ]
      },
      independent: {
        label: 'Independent Media',
        description:
          'Watch independent conservative channels sharing commentary, analysis, and documentaries from grassroots creators and think tanks.',
        feeds: [
          { id: 'yt-collegefix', name: 'The College Fix', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCQ1j1rJ7oO16N6-tWcK18hA', kind: 'video' },
          { id: 'yt-humanevents', name: 'Human Events', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC_oY8o3vU0o3O0U3E0o3Q6A', kind: 'video' },
          { id: 'yt-americanthinker', name: 'American Thinker', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCmF-c0qg7sL8W_r9oE8U36g', kind: 'video' },
          { id: 'yt-americangreatness', name: 'American Greatness', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCb1fA3oA9d7R_9G8s4iF-gA', kind: 'video' },
          { id: 'yt-bulwark', name: 'The Bulwark', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UChsX9hVfS1l9oF1i5kU_8_w', kind: 'video' },
          { id: 'yt-reason', name: 'Reason', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC0L_4p1v_YwM7W1t2L1P0Tw', kind: 'video' },
          { id: 'yt-heritage', name: 'Heritage Foundation', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCB38r92l9f1V_fV9V2N0D3g', kind: 'video' },
          { id: 'yt-frontpagemag', name: 'FrontPage Mag', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC-Q_1c_K1gQ6k3j9v1p1W2w', kind: 'video' },
          { id: 'yt-ijr', name: 'IJR (Independent Journal Review)', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCcK_s04g7xS-d9jG7tP9V3g', kind: 'video' },
          { id: 'yt-mrctv', name: 'MRCTV (Media Research Center)', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCuJbV4Uj951eS52I93s99eQ', kind: 'video' },
          { id: 'yt-newboston', name: 'New Boston Post', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC2s_2H6F-T2c_5Q5T8P2H1g', kind: 'video' },
          { id: 'yt-americanconservative', name: 'The American Conservative', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCv_d_Hw3JzYt_J_wK6T2P0A', kind: 'video' }
        ]
      },
      christian: {
        label: 'Christian Media',
        description:
          'View Christian news and ministry broadcasts featuring faith-driven stories, sermons, and worldview discussions updated daily.',
        feeds: [
          { id: 'yt-christianpost', name: 'The Christian Post', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCn-S1D5P-3f9L-z6Zf7g82Q', kind: 'video' },
          { id: 'yt-cbn', name: 'CBN News', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCyD0C6D7k3gI5tJ0Bf0_gSg', kind: 'video' },
          { id: 'yt-christiantoday', name: 'Christian Today', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCJpS4_G6-fQz33n4tS0y8iA', kind: 'video' },
          { id: 'yt-world', name: 'WORLD News Group', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCpP8T-lV1f_k5x_y1s0xQfQ', kind: 'video' },
          { id: 'yt-cna', name: 'Catholic News Agency', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UCr9K6b11-B6hY6f6z0v9-gA', kind: 'video' },
          { id: 'yt-lifesite', name: 'LifeSiteNews', url: 'https://www.youtube.com/feeds/videos.xml?channel_id=UC-QG-jC49271GvC5b-21gqg', kind: 'video' }
        ]
      }
    },
    social: {
      mainstream: {
        label: 'Mainstream',
        description:
          'Track mainstream conservative social media posts with real-time X feeds from national newsrooms and commentators.',
        feeds: [
          { id: 'social-breitbart', name: 'Breitbart (X)', url: 'https://nitter.net/BreitbartNews/rss' },
          { id: 'social-fox', name: 'Fox News (X)', url: 'https://nitter.net/FoxNews/rss' },
          { id: 'social-dailywire', name: 'Daily Wire (X)', url: 'https://nitter.net/realDailyWire/rss' },
          { id: 'social-newsmax', name: 'Newsmax (X)', url: 'https://nitter.net/Newsmax/rss' },
          { id: 'social-epochtimes', name: 'Epoch Times (X)', url: 'https://nitter.net/EpochTimes/rss' }
        ]
      },
      independent: {
        label: 'Independent Media',
        description:
          'Monitor independent media voices on social platforms to catch viral clips, grassroots activism, and libertarian commentary as it happens.',
        feeds: [
          { id: 'social-blaze', name: 'The Blaze (X)', url: 'https://nitter.net/TheBlaze/rss' },
          { id: 'social-lifesite', name: 'LifeSiteNews (X)', url: 'https://nitter.net/LifeSite/rss' },
          { id: 'social-americangreatness', name: 'American Greatness (X)', url: 'https://nitter.net/theamgreatness/rss' },
          { id: 'social-oann', name: 'OANN (X)', url: 'https://nitter.net/OANN/rss' },
          { id: 'social-mrc', name: 'MRC (X)', url: 'https://nitter.net/theMRC/rss' }
        ]
      },
      christian: {
        label: 'Christian Media',
        description:
          'Stay connected to Christian media ministries on X with uplifting headlines, prayer alerts, and faith-based cultural analysis.',
        feeds: [
          { id: 'social-christianpost', name: 'Christian Post (X)', url: 'https://nitter.net/ChristianPost/rss' },
          { id: 'social-cbn', name: 'CBN News (X)', url: 'https://nitter.net/CBNNews/rss' },
          { id: 'social-world', name: 'WORLD News (X)', url: 'https://nitter.net/world_mag/rss' },
          { id: 'social-cna', name: 'Catholic News Agency (X)', url: 'https://nitter.net/CatholicNewsSvc/rss' },
          { id: 'social-liberty', name: 'Liberty Daily (X)', url: 'https://nitter.net/libertydaily/rss' }
        ]
      }
    }
  };

  const shareTargets = [
    {
      key: 'facebook',
      name: 'Facebook',
      domain: 'facebook.com',
      build: (url) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`
    },
    {
      key: 'x',
      name: 'X',
      domain: 'x.com',
      build: (url, title) => {
        const text = title ? `${title} ${url}` : url;
        return `https://x.com/intent/post?text=${encodeURIComponent(text)}`;
      }
    },
    {
      key: 'truth',
      name: 'Truth Social',
      domain: 'truthsocial.com',
      build: (url, title) => `https://truthsocial.com/share?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title || url)}`
    },
    {
      key: 'gab',
      name: 'Gab',
      domain: 'gab.com',
      build: (url, title) => `https://gab.com/compose?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title || url)}`
    },
    {
      key: 'telegram',
      name: 'Telegram',
      domain: 'telegram.org',
      build: (url, title) => `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title || url)}`
    },
    {
      key: 'linkedin',
      name: 'LinkedIn',
      domain: 'linkedin.com',
      build: (url, title) => `https://www.linkedin.com/shareArticle?mini=true&url=${encodeURIComponent(url)}&title=${encodeURIComponent(title || url)}`
    },
    {
      key: 'sms',
      name: 'SMS',
      domain: 'messages.google.com',
      build: (url, title) => {
        const message = title ? `${title} ${url}` : url;
        return `sms:?body=${encodeURIComponent(message)}`;
      }
    },
    {
      key: 'email',
      name: 'Email',
      domain: 'mail.google.com',
      build: (url, title) => {
        const subject = title || 'HalHar Report';
        const body = title ? `${title}\n${url}` : url;
        return `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      }
    }
  ];

  const bookmarkTarget = {
    key: 'bookmark',
    name: 'Bookmark',
    domain: 'halhar.report',
    build: (url) => url
  };

  const shareTargetsWithBookmark = [...shareTargets, bookmarkTarget];

  const videoShareTargets = [
    {
      key: 'youtube',
      name: 'YouTube',
      domain: 'youtube.com',
      build: (url) => url
    },
    {
      key: 'twitter',
      name: 'X (Twitter)',
      domain: 'x.com',
      build: (url, title) => {
        const text = title ? `${title} ${url}` : url;
        return `https://x.com/intent/post?text=${encodeURIComponent(text)}`;
      }
    },
    {
      key: 'facebook',
      name: 'Facebook',
      domain: 'facebook.com',
      build: (url) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`
    }
  ];

  const collectFeedUrls = (groups) => {
    const urls = [];
    Object.values(groups).forEach((group) => {
      if (!group || !Array.isArray(group.feeds)) return;
      group.feeds.forEach((feed) => {
        if (feed && feed.url) {
          urls.push(feed.url.trim());
        }
      });
    });
    return urls;
  };

  const allowedFeedUrls = Array.from(
    new Set([
      ...collectFeedUrls(feedGroups.home),
      ...collectFeedUrls(feedGroups.videos),
      ...collectFeedUrls(feedGroups.social)
    ])
  );

  const config = {
    feedGroups,
    shareTargets,
    shareTargetsWithBookmark,
    bookmarkTarget,
    videoShareTargets,
    allowedFeedUrls
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = config;
  } else if (global) {
    global.HalHarConfig = config;
  }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : this);
