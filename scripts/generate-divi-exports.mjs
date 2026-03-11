import fs from "node:fs";
import path from "node:path";

const ROOT = process.cwd();
const SRC_DIR = path.join(ROOT, "src");
const ASSETS_DIR = path.join(SRC_DIR, "assets");
const EXPORTS_DIR = path.join(ROOT, "divi-exports");
const PROJECTS_FILE = path.join(SRC_DIR, "data", "projects.ts");
const ASSET_BASE_URL = "https://digitalmindflow.local/assets";
const BUILDER_VERSION = 0.7;

const MEDIA_LIBRARY_ASSETS = {
  "dm-logo.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/Artboard-2_9.png",
  "portfolio-ai.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-ai.jpg",
  "portfolio-email.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-email.jpg",
  "portfolio-ads.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-ads.jpg",
  "portfolio-web.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-web.jpg",
  "portfolio-social.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-social.jpg",
  "portfolio-brand.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/portfolio-brand.jpg",
  "hero-bg.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/hero-bg.jpg",
  "about-creative.jpg": "https://mindflowdigital.com/wp-content/uploads/2026/02/about-creative.jpg",
  "approach.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/approach.svg",
  "vision.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/vision.svg",
  "mission.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/mission.svg",
  "web.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/web.svg",
  "ppc.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/ppc.svg",
  "seo.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/seo.svg",
  "email.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/email.svg",
  "social.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/social.svg",
  "consultation.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/consultation.svg",
  "ai-ads.svg": "https://mindflowdigital.com/wp-content/uploads/2026/02/ai-ads.svg",
};

const COLOR_DEFINITIONS = {
  background: {
    id: "gcid-dmf-background",
    label: "DMF Background",
    value: "#fafafa",
  },
  card: {
    id: "gcid-dmf-card",
    label: "DMF Card",
    value: "#edeced",
  },
  foreground: {
    id: "gcid-dmf-foreground",
    label: "DMF Foreground",
    value: "#131b26",
  },
  muted: {
    id: "gcid-dmf-muted",
    label: "DMF Muted",
    value: "#486262",
  },
  primary: {
    id: "gcid-dmf-primary",
    label: "DMF Primary Surface",
    value: "#131b26",
  },
  overlay: {
    id: "gcid-dmf-overlay",
    label: "DMF Overlay",
    value: "#2b5b5b",
  },
  border: {
    id: "gcid-dmf-border",
    label: "DMF Border",
    value: "#a1a5a4",
  },
  accent: {
    id: "gcid-dmf-accent",
    label: "DMF Accent",
    value: "#941213",
  },
  accentDeep: {
    id: "gcid-dmf-accent-deep",
    label: "DMF Accent Deep",
    value: "#893637",
  },
  white: {
    id: "gcid-dmf-white",
    label: "DMF White",
    value: "#fafafa",
  },
};

const COLOR_VALUES = Object.fromEntries(
  Object.entries(COLOR_DEFINITIONS).map(([key, definition]) => [key, definition.value])
);

const GLOBAL_COLOR_IDS = Object.fromEntries(
  Object.entries(COLOR_DEFINITIONS).map(([key, definition]) => [key, definition.id])
);

function colorCssVar(key) {
  const colorId = GLOBAL_COLOR_IDS[key];
  const fallback = COLOR_VALUES[key];
  return `var(--${colorId}, ${fallback})`;
}

const COLORS = Object.fromEntries(
  Object.keys(COLOR_DEFINITIONS).map((key) => [key, colorCssVar(key)])
);

const GLOBAL_VARIABLE_IDS = {
  headingFont: "gvid-dmf-heading-font",
  bodyFont: "gvid-dmf-body-font",
  textXs: "gvid-dmf-text-xs",
  textSm: "gvid-dmf-text-sm",
  textBase: "gvid-dmf-text-base",
  textLg: "gvid-dmf-text-lg",
  spaceXs: "gvid-dmf-space-xs",
  spaceSm: "gvid-dmf-space-sm",
  spaceMd: "gvid-dmf-space-md",
  spaceLg: "gvid-dmf-space-lg",
  spaceXl: "gvid-dmf-space-xl",
  radiusSm: "gvid-dmf-radius-sm",
  radiusMd: "gvid-dmf-radius-md",
  radiusLg: "gvid-dmf-radius-lg",
  radiusXl: "gvid-dmf-radius-xl",
};

function cssVar(id) {
  return `var(--${id})`;
}

const FONTS = {
  heading: cssVar(GLOBAL_VARIABLE_IDS.headingFont),
  body: cssVar(GLOBAL_VARIABLE_IDS.bodyFont),
};

const TOKENS = {
  textXs: cssVar(GLOBAL_VARIABLE_IDS.textXs),
  textSm: cssVar(GLOBAL_VARIABLE_IDS.textSm),
  textBase: cssVar(GLOBAL_VARIABLE_IDS.textBase),
  textLg: cssVar(GLOBAL_VARIABLE_IDS.textLg),
  spaceXs: cssVar(GLOBAL_VARIABLE_IDS.spaceXs),
  spaceSm: cssVar(GLOBAL_VARIABLE_IDS.spaceSm),
  spaceMd: cssVar(GLOBAL_VARIABLE_IDS.spaceMd),
  spaceLg: cssVar(GLOBAL_VARIABLE_IDS.spaceLg),
  spaceXl: cssVar(GLOBAL_VARIABLE_IDS.spaceXl),
  radiusSm: cssVar(GLOBAL_VARIABLE_IDS.radiusSm),
  radiusMd: cssVar(GLOBAL_VARIABLE_IDS.radiusMd),
  radiusLg: cssVar(GLOBAL_VARIABLE_IDS.radiusLg),
  radiusXl: cssVar(GLOBAL_VARIABLE_IDS.radiusXl),
};

const DIVI_GLOBAL_VARIABLES = [
  {
    type: "fonts",
    id: GLOBAL_VARIABLE_IDS.headingFont,
    label: "DMF Heading Font",
    value: "Sora, 'Trebuchet MS', sans-serif",
    order: 1,
    status: "active",
  },
  {
    type: "fonts",
    id: GLOBAL_VARIABLE_IDS.bodyFont,
    label: "DMF Body Font",
    value: "'DM Sans', 'Segoe UI', sans-serif",
    order: 2,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.textXs,
    label: "DMF Text XS",
    value: "clamp(0.75rem, calc(0.735rem + 0.12vw), 0.8125rem)",
    order: 3,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.textSm,
    label: "DMF Text SM",
    value: "clamp(0.8125rem, calc(0.79rem + 0.16vw), 0.875rem)",
    order: 4,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.textBase,
    label: "DMF Text Base",
    value: "clamp(0.9375rem, calc(0.915rem + 0.18vw), 1rem)",
    order: 5,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.textLg,
    label: "DMF Text LG",
    value: "clamp(1rem, calc(0.97rem + 0.28vw), 1.125rem)",
    order: 6,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.spaceXs,
    label: "DMF Space XS",
    value: "0.75rem",
    order: 7,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.spaceSm,
    label: "DMF Space SM",
    value: "1rem",
    order: 8,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.spaceMd,
    label: "DMF Space MD",
    value: "1.5rem",
    order: 9,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.spaceLg,
    label: "DMF Space LG",
    value: "2rem",
    order: 10,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.spaceXl,
    label: "DMF Space XL",
    value: "3rem",
    order: 11,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.radiusSm,
    label: "DMF Radius SM",
    value: "0.875rem",
    order: 12,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.radiusMd,
    label: "DMF Radius MD",
    value: "1.25rem",
    order: 13,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.radiusLg,
    label: "DMF Radius LG",
    value: "1.5rem",
    order: 14,
    status: "active",
  },
  {
    type: "numbers",
    id: GLOBAL_VARIABLE_IDS.radiusXl,
    label: "DMF Radius XL",
    value: "2rem",
    order: 15,
    status: "active",
  },
];

const DIVI_GLOBAL_COLORS = Object.values(COLOR_DEFINITIONS).map((definition) => [
  definition.id,
  {
    color: definition.value,
    label: definition.label,
    status: "active",
  },
]);

const ABOUT_VALUES = [
  {
    title: "Our Mission",
    marker: "M",
    iconFile: "mission.svg",
    text: "To develop trusted business partnerships by providing the highest level of digital marketing services that contribute to our client's growth, success, and the community's development.",
  },
  {
    title: "Our Vision",
    marker: "V",
    iconFile: "vision.svg",
    text: "Our team consists of highly skilled professionals who are passionate about what they do. We believe that if you communicate with people right, you can gain excellence.",
  },
  {
    title: "Our Approach",
    marker: "A",
    iconFile: "approach.svg",
    text: "Through creative and customized strategy, we meet your business expectations. We use the latest tools, trends, and the appropriate platforms for your brand to achieve the best results.",
  },
];

const SERVICE_CARDS = [
  {
    title: "Consultation",
    iconFile: "consultation.svg",
    description:
      "Digital marketing services built on strategy, driven by data and delivering effective practices.",
    items: [
      "Vision & Brand Positioning",
      "Competitive Analysis",
      "Market Research",
      "Target Audience & Re-targeting",
    ],
  },
  {
    title: "Social Media Marketing",
    iconFile: "social.svg",
    description:
      "Custom content for the proper platform for your niche that attracts potential customers.",
    items: [
      "Strategy & Monthly Content Plan",
      "Instagram, Facebook, LinkedIn, TikTok",
      "Content Creation & Hashtags",
      "Social Media Advertising",
    ],
  },
  {
    title: "Email Marketing",
    iconFile: "email.svg",
    description:
      "We can make some inboxes really happy. We know how your potential customers actually open your emails.",
    items: [
      "List Building & Segmentation",
      "Email Design & Content",
      "Automation & Campaigns",
      "Analytics & Reporting",
    ],
  },
  {
    title: "SEO",
    iconFile: "seo.svg",
    description:
      "Comprehensive search engine optimization to boost your organic visibility and rankings.",
    items: [
      "On-page & Off-page Optimization",
      "Local SEO",
      "Technical SEO",
      "Content Strategy",
    ],
  },
  {
    title: "PPC & Google Ads",
    iconFile: "ppc.svg",
    description:
      "Beat the competition and take your business to the top of results with the right strategy and keywords.",
    items: [
      "Google & Bing Ads",
      "Facebook & Display Ads",
      "Audience Targeting & Leads",
      "Conversion Tracking & Analytics",
    ],
  },
  {
    title: "Web Design",
    iconFile: "web.svg",
    description:
      "Increase your online presence with a website that reflects your brand and takes your business to the next level.",
    items: [
      "Web Development & Redesign",
      "Content Creation & Visuals",
      "SEO & Performance",
      "Testing & Launch",
    ],
  },
  {
    title: "AI-Powered Advertising",
    iconFile: "ai-ads.svg",
    description:
      "Next-gen advertising leveraging AI for smarter targeting, creative generation, and presence across AI answer engines.",
    items: [
      "LLM Ads (ChatGPT, Gemini, Perplexity)",
      "Generative Engine Optimization (GEO)",
      "CTV Ads & Shoppable Experiences",
      "AI-Generated Creatives & Commercials",
      "AI Influencers & Virtual Characters",
      "Programmatic Agentic Advertising",
    ],
  },
  {
    title: "Marketing Training",
    description:
      "Empower your team with hands-on workshops and frameworks to execute data-driven marketing campaigns independently.",
    items: [
      "Team Workshops & Strategy Sessions",
      "SEO, PPC & Social Media Training",
      "Analytics & Reporting Mastery",
      "Custom Playbooks & SOPs",
    ],
  },
  {
    title: "AI Training",
    description:
      "Equip your team with the skills to integrate AI tools into everyday workflows for productivity and competitive edge.",
    items: [
      "AI Tool Mastery (ChatGPT, Midjourney & more)",
      "Prompt Engineering Workshops",
      "Workflow Automation with AI",
      "Custom AI Playbooks & Guidelines",
    ],
  },
];

const PROCESS_STEPS = [
  {
    step: "01",
    title: "Discovery & Strategy",
    description:
      "We start with a deep dive into your business, understanding your goals, audience, and competition. A comprehensive strategy is developed that is tailored to your unique needs.",
  },
  {
    step: "02",
    title: "Execute & Optimize",
    description:
      "We implement the strategy across the right channels - social media, SEO, ads, email. Every campaign is continuously monitored, tested, and refined for peak performance.",
  },
  {
    step: "03",
    title: "Grow & Scale",
    description:
      "With data-driven insights and transparent reporting, we identify opportunities to expand your reach, increase conversions, and scale your success to the next level.",
  },
];

const CASE_STUDY_PAGE_PATH = (slug) => `/${slug}/`;

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function escapeAttr(value) {
  return escapeHtml(value);
}

function pxToRem(pxValue) {
  const numeric = Number.parseFloat(pxValue);
  if (Number.isNaN(numeric)) {
    return pxValue;
  }

  if (numeric === 0) {
    return "0";
  }

  return `${Number((numeric / 16).toFixed(4))}rem`;
}

function formatRem(remValue) {
  return `${Number(remValue.toFixed(4))}rem`;
}

function fluidFontSize(remValue) {
  if (remValue <= 0) {
    return "0";
  }

  let minScale = 0.94;
  let maxScale = 1.06;
  let vw = 0.18;

  if (remValue <= 0.85) {
    minScale = 0.94;
    maxScale = 1.02;
    vw = 0.12;
  } else if (remValue <= 1.05) {
    minScale = 0.95;
    maxScale = 1.06;
    vw = 0.18;
  } else if (remValue <= 1.35) {
    minScale = 0.94;
    maxScale = 1.1;
    vw = 0.24;
  } else if (remValue <= 1.8) {
    minScale = 0.92;
    maxScale = 1.14;
    vw = 0.4;
  } else {
    minScale = 0.9;
    maxScale = 1.18;
    vw = 0.7;
  }

  const min = formatRem(remValue * minScale);
  const preferredBase = formatRem(remValue * minScale);
  const max = formatRem(remValue * maxScale);

  return `clamp(${min}, calc(${preferredBase} + ${vw}vw), ${max})`;
}

function responsiveFontSize(value) {
  if (typeof value !== "string") {
    return value;
  }

  const normalized = responsiveValue(value);

  if (
    normalized === "0" ||
    normalized.includes("clamp(") ||
    normalized.includes("var(--gvid-") ||
    normalized.includes("calc(")
  ) {
    return normalized;
  }

  const remMatch = normalized.match(/^(-?\d*\.?\d+)rem$/);
  if (!remMatch) {
    return normalized;
  }

  return fluidFontSize(Number.parseFloat(remMatch[1]));
}

function responsiveValue(value) {
  if (typeof value !== "string") {
    return value;
  }

  return value.replace(/(-?\d*\.?\d+)px\b/g, (_, pxValue) => pxToRem(pxValue));
}

function styleString(styles) {
  return Object.entries(styles)
    .filter(([, value]) => value !== undefined && value !== null && value !== "")
    .map(([key, value]) =>
      `${key}:${key === "font-size" ? responsiveFontSize(value) : responsiveValue(value)}`
    )
    .join(";");
}

function rgba(color, alpha) {
  if (typeof color === "string" && color.startsWith("var(")) {
    return `color-mix(in srgb, ${color} ${Number((alpha * 100).toFixed(2))}%, transparent)`;
  }

  const raw = color.replace("#", "");
  const value = raw.length === 3 ? raw.split("").map((part) => part + part).join("") : raw;
  const int = Number.parseInt(value, 16);
  const r = (int >> 16) & 255;
  const g = (int >> 8) & 255;
  const b = int & 255;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function assetUrl(fileName) {
  return MEDIA_LIBRARY_ASSETS[fileName] || `${ASSET_BASE_URL}/${fileName}`;
}

function chunk(items, size) {
  const chunks = [];
  for (let index = 0; index < items.length; index += size) {
    chunks.push(items.slice(index, index + size));
  }
  return chunks;
}

function block(moduleName, attrs = {}, inner = null) {
  const hasAttrs = attrs && Object.keys(attrs).length > 0;
  const attrsJson = hasAttrs ? ` ${JSON.stringify(attrs)}` : "";
  if (inner === null) {
    return `<!-- wp:divi/${moduleName}${attrsJson} /-->`;
  }
  return `<!-- wp:divi/${moduleName}${attrsJson} -->${inner}<!-- /wp:divi/${moduleName} -->`;
}

function placeholder(inner) {
  return `<!-- wp:divi/placeholder -->${inner}<!-- /wp:divi/placeholder -->`;
}

function section(children, label) {
  return block(
    "section",
    {
      builderVersion: BUILDER_VERSION,
      module: {
        meta: {
          adminLabel: {
            desktop: {
              value: label,
            },
          },
        },
      },
    },
    children
  );
}

function row(children, structure, label) {
  return block(
    "row",
    {
      builderVersion: BUILDER_VERSION,
      module: {
        meta: {
          adminLabel: {
            desktop: {
              value: label,
            },
          },
        },
        advanced: {
          columnStructure: {
            desktop: {
              value: structure,
            },
          },
        },
      },
    },
    children
  );
}

function column(children, type, label) {
  return block(
    "column",
    {
      builderVersion: BUILDER_VERSION,
      module: {
        meta: {
          adminLabel: {
            desktop: {
              value: label,
            },
          },
        },
        advanced: {
          type: {
            desktop: {
              value: type,
            },
          },
        },
      },
    },
    children
  );
}

function textModule(html, label) {
  return block("text", {
    builderVersion: BUILDER_VERSION,
    module: {
      meta: {
        adminLabel: {
          desktop: {
            value: label,
          },
        },
      },
    },
    content: {
      innerContent: {
        desktop: {
          value: html,
        },
      },
    },
  });
}

function menuModule(label) {
  return block("menu", {
    builderVersion: BUILDER_VERSION,
    module: {
      meta: {
        adminLabel: {
          desktop: {
            value: label,
          },
        },
      },
    },
    logo: {
      innerContent: {
        desktop: {
          value: {
            src: assetUrl("dm-logo.jpg"),
            alt: "Digital MindFlow",
            titleText: "Digital MindFlow",
            linkUrl: "/",
            linkTarget: "off",
          },
        },
      },
      decoration: {
        sizing: {
          desktop: {
            value: {
              width: "clamp(8rem, calc(7.1rem + 3vw), 10.25rem)",
              maxWidth: "clamp(8rem, calc(7.1rem + 3vw), 10.25rem)",
              height: "auto",
              maxHeight: "4.25rem",
            },
          },
        },
      },
    },
    menu: {
      advanced: {
        menuId: {
          desktop: {
            value: "",
          },
        },
        style: {
          desktop: {
            value: "left_aligned",
          },
        },
        activeLinkColor: {
          desktop: {
            value: COLORS.accent,
          },
        },
      },
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                family: FONTS.body,
                weight: "600",
                size: TOKENS.textSm,
                color: COLORS.foreground,
                lineHeight: "1.5em",
                letterSpacing: "0.01em",
              },
            },
          },
        },
      },
    },
    menuDropdown: {
      advanced: {
        direction: {
          desktop: {
            value: "downwards",
          },
        },
        lineColor: {
          desktop: {
            value: COLORS.border,
          },
        },
        activeLinkColor: {
          desktop: {
            value: COLORS.accent,
          },
        },
      },
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                family: FONTS.body,
                weight: "500",
                size: TOKENS.textSm,
                color: COLORS.foreground,
                lineHeight: "1.5em",
              },
            },
          },
        },
      },
    },
    menuMobile: {
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                family: FONTS.body,
                weight: "600",
                size: TOKENS.textBase,
                color: COLORS.foreground,
                lineHeight: "1.5em",
              },
            },
          },
        },
      },
    },
    cartQuantity: {
      advanced: {
        show: {
          desktop: {
            value: "off",
          },
        },
      },
    },
    cartIcon: {
      advanced: {
        show: {
          desktop: {
            value: "off",
          },
        },
      },
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                color: COLORS.foreground,
              },
            },
          },
        },
      },
    },
    searchIcon: {
      advanced: {
        show: {
          desktop: {
            value: "off",
          },
        },
      },
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                color: COLORS.foreground,
              },
            },
          },
        },
      },
    },
    hamburgerMenuIcon: {
      decoration: {
        font: {
          font: {
            desktop: {
              value: {
                color: COLORS.foreground,
                size: TOKENS.textLg,
              },
            },
          },
        },
      },
    },
  });
}

function gradientText(text) {
  return `<span style="${styleString({
    display: "inline-block",
    background: `linear-gradient(135deg, ${COLORS.accent}, ${COLORS.accentDeep})`,
    color: "transparent",
    "-webkit-background-clip": "text",
    "background-clip": "text",
  })}">${escapeHtml(text)}</span>`;
}

function kicker(text) {
  return `<div style="${styleString({
    "font-family": FONTS.body,
    "font-size": TOKENS.textXs,
    "font-weight": "700",
    "letter-spacing": "0.22em",
    "text-transform": "uppercase",
    color: COLORS.accent,
    "margin-bottom": `calc(${TOKENS.spaceXs} + 0.125rem)`,
  })}">${escapeHtml(text)}</div>`;
}

function buttonLink(label, url, variant = "primary", isBlock = false) {
  const base = {
    display: isBlock ? "block" : "inline-block",
    padding: `calc(${TOKENS.spaceSm} - 0.125rem) calc(${TOKENS.spaceMd} + 0rem)`,
    "border-radius": TOKENS.radiusMd,
    "font-family": FONTS.body,
    "font-size": TOKENS.textBase,
    "font-weight": "700",
    "line-height": "1.1",
    "text-align": "center",
    "text-decoration": "none",
    transition: "all 0.2s ease",
  };
  const variantStyles =
    variant === "outline"
      ? {
          color: COLORS.white,
          border: `1px solid ${rgba(COLORS.white, 0.25)}`,
          background: rgba(COLORS.white, 0.05),
        }
      : variant === "light-outline"
      ? {
          color: COLORS.foreground,
          border: `1px solid ${COLORS.border}`,
          background: COLORS.white,
        }
      : {
          color: COLORS.foreground,
          border: `1px solid ${COLORS.accent}`,
          background: `linear-gradient(135deg, ${COLORS.accent}, ${COLORS.accentDeep})`,
          "box-shadow": `0 16px 36px ${rgba(COLORS.accent, 0.25)}`,
        };

  return `<a href="${escapeAttr(url)}" style="${styleString({
    ...base,
    ...variantStyles,
  })}">${escapeHtml(label)}</a>`;
}

function pill(text, dark = false) {
  return `<span style="${styleString({
    display: "inline-block",
    padding: `calc(${TOKENS.spaceXs} - 0.3125rem) calc(${TOKENS.spaceSm} - 0.25rem)`,
    "border-radius": "999rem",
    background: dark ? rgba(COLORS.white, 0.12) : rgba(COLORS.accent, 0.14),
    color: dark ? COLORS.white : COLORS.foreground,
    "font-family": FONTS.body,
    "font-size": TOKENS.textXs,
    "font-weight": "700",
    "letter-spacing": "0.04em",
  })}">${escapeHtml(text)}</span>`;
}

function tag(text) {
  return `<span style="${styleString({
    display: "inline-block",
    padding: `calc(${TOKENS.spaceXs} - 0.375rem) calc(${TOKENS.spaceXs} + 0.125rem)`,
    "border-radius": "999rem",
    background: COLORS.accent,
    color: COLORS.foreground,
    "font-family": FONTS.body,
    "font-size": TOKENS.textXs,
    "font-weight": "700",
  })}">${escapeHtml(text)}</span>`;
}

function statCard(label, value, inverted = false) {
  return `<div style="${styleString({
    background: inverted ? rgba(COLORS.white, 0.08) : COLORS.card,
    border: inverted ? `1px solid ${rgba(COLORS.white, 0.12)}` : `1px solid ${COLORS.border}`,
    "border-radius": TOKENS.radiusLg,
    padding: `${TOKENS.spaceMd} calc(${TOKENS.spaceMd} - 0.375rem)`,
    "text-align": "center",
  })}">
    <div style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "clamp(1.75rem, 3vw, 1.875rem)",
      "font-weight": "700",
      color: COLORS.accent,
      "margin-bottom": "0.375rem",
      "line-height": "1.1",
    })}">${escapeHtml(value)}</div>
    <div style="${styleString({
      "font-family": FONTS.body,
      "font-size": TOKENS.textSm,
      color: inverted ? rgba(COLORS.white, 0.72) : COLORS.muted,
    })}">${escapeHtml(label)}</div>
  </div>`;
}

function imageHtml(src, alt, options = {}) {
  return `<img src="${escapeAttr(src)}" alt="${escapeAttr(alt)}" style="${styleString({
    display: "block",
    width: "100%",
    height: options.height || "auto",
    "object-fit": options.objectFit || "cover",
    "border-radius": options.radius || TOKENS.radiusLg,
    "box-shadow": options.shadow || `0 24px 56px ${rgba(COLORS.primary, 0.14)}`,
  })}">`;
}

function iconBadgeHtml(fileName, label, size = "1.5rem") {
  return `<img src="${escapeAttr(assetUrl(fileName))}" alt="${escapeAttr(label)}" style="${styleString({
    width: size,
    height: size,
    display: "block",
    "object-fit": "contain",
  })}">`;
}

function contentCard(inner, extra = {}) {
  return `<div style="${styleString({
    background: extra.background || COLORS.white,
    border: `1px solid ${extra.borderColor || COLORS.border}`,
    "border-radius": extra.radius || TOKENS.radiusLg,
    padding: extra.padding || `calc(${TOKENS.spaceLg} - 0.25rem)`,
    "box-shadow": extra.shadow || `0 16px 36px ${rgba(COLORS.primary, 0.08)}`,
    height: "100%",
  })}">${inner}</div>`;
}

function listHtml(items, markerColor = COLORS.accent) {
  return `<div>${items
    .map(
      (item) =>
        `<div style="${styleString({
          display: "flex",
          gap: "10px",
          "align-items": "flex-start",
          "margin-bottom": "10px",
        })}">
          <span style="${styleString({
            display: "inline-block",
            width: "8px",
            height: "8px",
            "border-radius": "999px",
            background: markerColor,
            "margin-top": "8px",
            "flex-shrink": "0",
          })}"></span>
          <span style="${styleString({
            "font-family": FONTS.body,
            "font-size": "15px",
            "line-height": "1.7",
            color: COLORS.foreground,
          })}">${escapeHtml(item)}</span>
        </div>`
    )
    .join("")}</div>`;
}

function projectCardHtml(project, options = {}) {
  const href = options.href || CASE_STUDY_PAGE_PATH(project.slug);
  const image = assetUrl(project.imageFile);
  const services = project.services.map((service) => pill(service)).join(" ");
  const outcomeText =
    options.withOutcome === false
      ? ""
      : `<div style="${styleString({
          "margin-top": "18px",
        })}">
          <div style="${styleString({
            "font-family": FONTS.body,
            "font-size": "12px",
            "font-weight": "700",
            "text-transform": "uppercase",
            "letter-spacing": "0.12em",
            color: COLORS.accent,
            "margin-bottom": "6px",
          })}">Outcome</div>
          <div style="${styleString({
            "font-family": FONTS.body,
            "font-size": "15px",
            "line-height": "1.7",
            color: COLORS.foreground,
          })}">${escapeHtml(project.outcome)}</div>
        </div>`;

  const goalText =
    options.withGoal === false
      ? ""
      : `<div>
          <div style="${styleString({
            "font-family": FONTS.body,
            "font-size": "12px",
            "font-weight": "700",
            "text-transform": "uppercase",
            "letter-spacing": "0.12em",
            color: COLORS.accent,
            "margin-bottom": "6px",
          })}">Goal</div>
          <div style="${styleString({
            "font-family": FONTS.body,
            "font-size": "15px",
            "line-height": "1.7",
            color: COLORS.muted,
          })}">${escapeHtml(project.goal)}</div>
        </div>`;

  return contentCard(
    `<div style="${styleString({
      "margin-bottom": "18px",
    })}">
      <a href="${escapeAttr(href)}" style="${styleString({
        display: "block",
        "text-decoration": "none",
      })}">
        ${imageHtml(image, project.title, {
          height: options.imageHeight || "280px",
          radius: "20px",
          shadow: "none",
        })}
      </a>
      <div style="${styleString({
        position: "relative",
        "margin-top": "-258px",
        padding: "16px",
        "pointer-events": "none",
      })}">
        ${tag(project.category)}
      </div>
    </div>
    <div style="${styleString({
      "font-family": FONTS.body,
      "font-size": "12px",
      "font-weight": "700",
      "text-transform": "uppercase",
      "letter-spacing": "0.14em",
      color: COLORS.muted,
      "margin-bottom": "10px",
    })}">${escapeHtml(project.client)}</div>
    <h3 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "28px",
      "font-weight": "700",
      "line-height": "1.2",
      color: COLORS.foreground,
      "margin": "0 0 14px 0",
    })}">${escapeHtml(project.title)}</h3>
    <div style="${styleString({
      display: "flex",
      gap: "8px",
      "flex-wrap": "wrap",
      "margin-bottom": "18px",
    })}">${services}</div>
    ${goalText}
    ${outcomeText}
    <div style="${styleString({
      "margin-top": "18px",
    })}">
      <a href="${escapeAttr(href)}" style="${styleString({
        "font-family": FONTS.body,
        "font-size": "15px",
        "font-weight": "700",
        color: COLORS.accentDeep,
        "text-decoration": "none",
      })}">${escapeHtml(options.linkLabel || "View Case Study")} &rarr;</a>
    </div>`,
    {
      background: COLORS.card,
      padding: "24px",
    }
  );
}

function serviceCardHtml(service) {
  return contentCard(
    `<div style="${styleString({
      display: "inline-flex",
      width: "3rem",
      height: "3rem",
      "align-items": "center",
      "justify-content": "center",
      "border-radius": TOKENS.radiusMd,
      background: rgba(COLORS.accent, 0.14),
      color: COLORS.accent,
      "font-family": FONTS.heading,
      "font-size": "1rem",
      "font-weight": "700",
      "margin-bottom": TOKENS.spaceMd,
    })}">${service.iconFile ? iconBadgeHtml(service.iconFile, service.title, "1.25rem") : escapeHtml(service.title.charAt(0))}</div>
    <h3 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "1.125rem",
      "font-weight": "600",
      "line-height": "1.25",
      color: COLORS.foreground,
      "margin": "0 0 0.5rem 0",
    })}">${escapeHtml(service.title)}</h3>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "0.875rem",
      "line-height": "1.7",
      color: COLORS.muted,
      "margin": "0 0 1rem 0",
    })}">${escapeHtml(service.description)}</p>
    ${listHtml(service.items)}`,
    {
      background: COLORS.background,
      padding: "2rem",
    }
  );
}

function valueCardHtml(value) {
  return contentCard(
    `<div style="${styleString({
      "text-align": "center",
    })}"><div style="${styleString({
      display: "inline-flex",
      width: "3.5rem",
      height: "3.5rem",
      "align-items": "center",
      "justify-content": "center",
      "border-radius": TOKENS.radiusMd,
      background: rgba(COLORS.accent, 0.14),
      color: COLORS.accent,
      "font-family": FONTS.heading,
      "font-size": "1.125rem",
      "font-weight": "700",
      "margin-bottom": TOKENS.spaceMd,
    })}">${value.iconFile ? iconBadgeHtml(value.iconFile, value.title) : escapeHtml(value.marker)}</div>
    <h3 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "1.25rem",
      "font-weight": "600",
      "line-height": "1.25",
      color: COLORS.foreground,
      "margin": "0 0 0.75rem 0",
    })}">${escapeHtml(value.title)}</h3>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "0.875rem",
      "line-height": "1.8",
      color: COLORS.muted,
      "margin": "0",
    })}">${escapeHtml(value.text)}</p></div>`,
    {
      background: COLORS.card,
      padding: "2rem",
    }
  );
}

function processCardHtml(step) {
  return `<div style="${styleString({
    "text-align": "center",
    padding: "1rem 0",
  })}">
    <div style="${styleString({
      display: "inline-flex",
      width: "4rem",
      height: "4rem",
      "align-items": "center",
      "justify-content": "center",
      "border-radius": TOKENS.radiusLg,
      background: COLORS.primary,
      color: COLORS.white,
      "font-family": FONTS.heading,
      "font-size": "1.125rem",
      "font-weight": "700",
      "margin-bottom": "1.5rem",
      "box-shadow": `0 1.25rem 3.75rem ${rgba(COLORS.primary, 0.15)}`,
    })}">${escapeHtml(step.step)}</div>
    <div style="${styleString({
      display: "inline-flex",
      width: "2.5rem",
      height: "2.5rem",
      "align-items": "center",
      "justify-content": "center",
      "border-radius": "999rem",
      background: rgba(COLORS.accent, 0.14),
      color: COLORS.accent,
      "font-family": FONTS.heading,
      "font-size": "1rem",
      "font-weight": "700",
      "margin-bottom": "1rem",
    })}">${escapeHtml(step.step.charAt(1) || step.step.charAt(0))}</div>
    <h3 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "1.25rem",
      "font-weight": "600",
      "line-height": "1.25",
      color: COLORS.foreground,
      "margin": "0 0 0.75rem 0",
    })}">${escapeHtml(step.title)}</h3>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "0.875rem",
      "line-height": "1.8",
      color: COLORS.muted,
      margin: "0 auto",
      "max-width": "16rem",
    })}">${escapeHtml(step.description)}</p>
  </div>`;
}

function featuredProjectPreviewHtml(project) {
  return `<div style="${styleString({
    height: "100%",
  })}">
    <a href="/portfolio/" style="${styleString({
      display: "block",
      color: COLORS.foreground,
      "text-decoration": "none",
    })}">
      <div style="${styleString({
        position: "relative",
        overflow: "hidden",
        "border-radius": TOKENS.radiusLg,
        "aspect-ratio": "4 / 3",
        "margin-bottom": "1rem",
        background: COLORS.card,
      })}">
        ${imageHtml(assetUrl(project.imageFile), project.title, {
          height: "100%",
          radius: TOKENS.radiusLg,
          shadow: "none",
        })}
        <div style="${styleString({
          position: "absolute",
          inset: "0",
          background: rgba(COLORS.primary, 0.06),
        })}"></div>
        <div style="${styleString({
          position: "absolute",
          top: "0.75rem",
          left: "0.75rem",
        })}">
          ${tag(project.category)}
        </div>
      </div>
      <p style="${styleString({
        "font-family": FONTS.body,
        "font-size": "0.75rem",
        "font-weight": "500",
        color: COLORS.muted,
        "text-transform": "uppercase",
        "letter-spacing": "0.14em",
        "margin": "0 0 0.25rem 0",
      })}">${escapeHtml(project.client)}</p>
      <h3 style="${styleString({
        "font-family": FONTS.heading,
        "font-size": "1.125rem",
        "font-weight": "600",
        color: COLORS.foreground,
        margin: "0",
      })}">${escapeHtml(project.title)}</h3>
    </a>
  </div>`;
}

function heroShellHtml(inner) {
  return `<div style="${styleString({
    position: "relative",
    width: "100vw",
    "margin-left": "calc(50% - 50vw)",
    "margin-right": "calc(50% - 50vw)",
    overflow: "hidden",
    background: `url('${assetUrl("hero-bg.jpg")}') center/cover no-repeat`,
  })}">
    <div style="${styleString({
      position: "absolute",
      inset: "0",
      background: `linear-gradient(180deg, ${rgba(COLORS.primary, 0.7)}, ${rgba(COLORS.overlay, 0.82)})`,
    })}"></div>
    <div style="${styleString({
      position: "relative",
      "z-index": "1",
      "min-height": "calc(100vh - 1rem)",
      display: "flex",
      "align-items": "center",
      "justify-content": "center",
      "text-align": "center",
      padding: "clamp(6rem, 10vw, 8rem) 1.5rem clamp(4rem, 8vw, 6rem)",
      width: "100%",
    })}">
      <div style="${styleString({
      width: "100%",
      "max-width": "64rem",
      margin: "0 auto",
    })}">${inner}</div>
    </div>
  </div>`;
}

function homeHeroHtml() {
  return heroShellHtml(
    `<div style="${styleString({
      display: "inline-flex",
      "align-items": "center",
      gap: "0.5rem",
      padding: "0.375rem 1rem",
      "border-radius": "999rem",
      border: `1px solid ${rgba(COLORS.accent, 0.28)}`,
      background: rgba(COLORS.accent, 0.1),
      color: COLORS.accent,
      "font-family": FONTS.body,
      "font-size": "0.875rem",
      "font-weight": "700",
      "margin-bottom": "2rem",
    })}">Strategy &middot; Data &middot; Creativity</div>
    <h1 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "clamp(2.5rem, 6.5vw, 4.75rem)",
      "font-weight": "700",
      "line-height": "1.08",
      color: COLORS.white,
      margin: "0 0 1.5rem 0",
    })}">Providing a Strong ${gradientText("Online Presence")} Through Strategic Digital Marketing</h1>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "1.125rem",
      "line-height": "1.8",
      color: rgba(COLORS.white, 0.74),
      margin: "0 auto 2.5rem",
      "max-width": "42rem",
    })}">We offer strategic, modern and effective solutions for businesses that want to grow their online presence. Let us take you one step closer to your business goals.</p>
    <div style="${styleString({
      display: "flex",
      gap: "1rem",
      "justify-content": "center",
      "flex-wrap": "wrap",
    })}">
      ${buttonLink("Request Free Consultation", "/#contact")}
      ${buttonLink("Explore Our Services", "/#services", "outline")}
    </div>
    <div style="${styleString({
      position: "absolute",
      left: "50%",
      bottom: "2rem",
      transform: "translateX(-50%)",
      display: "flex",
      "align-items": "flex-start",
      "justify-content": "center",
      width: "1.5rem",
      height: "2.5rem",
      border: `0.125rem solid ${rgba(COLORS.white, 0.3)}`,
      "border-radius": "999rem",
      padding: "0.5rem 0 0",
    })}">
      <span style="${styleString({
        display: "inline-block",
        width: "0.375rem",
        height: "0.375rem",
        "border-radius": "999rem",
        background: COLORS.accent,
      })}"></span>
    </div>`
  );
}

function portfolioHeroHtml() {
  return `<div style="${styleString({
    background: COLORS.primary,
    width: "100vw",
    "margin-left": "calc(50% - 50vw)",
    "margin-right": "calc(50% - 50vw)",
    padding: "clamp(6rem, 10vw, 7.5rem) 1.5rem clamp(4rem, 8vw, 5.5rem)",
    "text-align": "center",
  })}">
    ${kicker("Our Work")}
    <h1 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "clamp(2.5rem, 6vw, 4.25rem)",
      "font-weight": "700",
      "line-height": "1.1",
      color: COLORS.white,
      margin: "0 0 1.125rem 0",
    })}">Recent ${gradientText("Projects")}</h1>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "1.125rem",
      "line-height": "1.8",
      color: rgba(COLORS.white, 0.72),
      "max-width": "42rem",
      margin: "0 auto",
    })}">Explore our portfolio of successful campaigns and projects. Each case study showcases our strategic approach and measurable results.</p>
  </div>`;
}

function caseStudyHeroLeftHtml(project) {
  return `<div style="${styleString({
    padding: "16px 0",
  })}">
    ${tag(project.category)}
    <h1 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "clamp(34px, 5vw, 56px)",
      "font-weight": "700",
      "line-height": "1.12",
      color: COLORS.white,
      "margin": "22px 0 12px 0",
    })}">${escapeHtml(project.title)}</h1>
    <div style="${styleString({
      "font-family": FONTS.body,
      "font-size": "18px",
      color: rgba(COLORS.white, 0.72),
      "margin-bottom": "18px",
    })}">${escapeHtml(project.client)}</div>
    <p style="${styleString({
      "font-family": FONTS.body,
      "font-size": "16px",
      "line-height": "1.75",
      color: rgba(COLORS.white, 0.74),
      "margin": "0 0 22px 0",
    })}"><strong style="color:${COLORS.white}">Goal:</strong> ${escapeHtml(project.goal)}</p>
    <div style="${styleString({
      display: "flex",
      gap: "12px",
      "align-items": "center",
      "flex-wrap": "wrap",
      "margin-bottom": "16px",
      "font-family": FONTS.body,
      "font-size": "14px",
      color: rgba(COLORS.white, 0.66),
    })}">
      <span>Timeline: ${escapeHtml(project.timeline)}</span>
    </div>
    <div style="${styleString({
      display: "flex",
      gap: "8px",
      "flex-wrap": "wrap",
    })}">${project.services.map((service) => pill(service, true)).join(" ")}</div>
  </div>`;
}

function contactInfoHtml() {
  return `<div id="contact" style="${styleString({
    padding: "0.625rem 0",
  })}">
    <h3 style="${styleString({
      "font-family": FONTS.heading,
      "font-size": "1.25rem",
      "font-weight": "600",
      color: COLORS.foreground,
      margin: "0 0 1.5rem 0",
    })}">Contact Information</h3>
    <div style="${styleString({
      display: "flex",
      "flex-direction": "column",
      gap: "1.25rem",
      "margin-bottom": "2rem",
    })}">
      ${[
        ["@","info@mindflowdigital.com","mailto:info@mindflowdigital.com"],
        ["P","+357 99 882116","tel:+35799882116"],
        ["M","Paphos, Cyprus",""],
      ].map(([icon,label,href]) =>
        `<${href ? "a" : "div"} ${href ? `href="${escapeAttr(href)}"` : ""} style="${styleString({
          display: "flex",
          "align-items": "center",
          gap: "0.75rem",
          color: href ? COLORS.muted : COLORS.muted,
          "text-decoration": "none",
        })}">
          <span style="${styleString({
            display: "inline-flex",
            width: "2.5rem",
            height: "2.5rem",
            "align-items": "center",
            "justify-content": "center",
            "border-radius": TOKENS.radiusMd,
            background: rgba(COLORS.accent, 0.12),
            color: COLORS.accent,
            "font-family": FONTS.heading,
            "font-size": "0.875rem",
            "font-weight": "700",
            "flex-shrink": "0",
          })}">${icon}</span>
          <span style="${styleString({
            "font-family": FONTS.body,
            "font-size": "0.875rem",
            color: href ? COLORS.muted : COLORS.muted,
          })}">${escapeHtml(label)}</span>
        </${href ? "a" : "div"}>`
      ).join("")}
    </div>
    ${contentCard(
      `<h4 style="${styleString({
        "font-family": FONTS.heading,
        "font-size": "1.125rem",
        "font-weight": "600",
        color: COLORS.white,
        margin: "0 0 0.5rem 0",
      })}">Book a Discovery Call</h4>
      <p style="${styleString({
        "font-family": FONTS.body,
        "font-size": "0.875rem",
        "line-height": "1.8",
        color: rgba(COLORS.white, 0.74),
        margin: "0 0 1rem 0",
      })}">Schedule a free 30-minute call with our team to discuss your business goals and how we can help.</p>
      ${buttonLink("Call Now", "tel:+35799882116")}`,
      {
        background: COLORS.primary,
        borderColor: rgba(COLORS.white, 0.08),
        padding: "1.5rem",
        shadow: "none",
      }
    )}`;
}

function contactFormHtml() {
  const fieldShell = (label, placeholderText, multiLine = false) =>
    `<div style="${styleString({
      "margin-bottom": "16px",
    })}">
      <div style="${styleString({
        "font-family": FONTS.body,
        "font-size": "13px",
        "font-weight": "700",
        color: COLORS.foreground,
        "margin-bottom": "8px",
      })}">${escapeHtml(label)}</div>
      <div style="${styleString({
        border: `1px solid ${COLORS.border}`,
        background: COLORS.background,
        "border-radius": "16px",
        padding: multiLine ? "18px 18px 86px" : "16px 18px",
        "font-family": FONTS.body,
        "font-size": "15px",
        color: COLORS.muted,
      })}">${escapeHtml(placeholderText)}</div>
    </div>`;

  return contentCard(
    `${fieldShell("Full Name *", "Your name")}
    ${fieldShell("Email Address *", "you@company.com")}
    ${fieldShell("Message *", "Tell us about your project and goals...", true)}
    <div style="${styleString({
      display: "flex",
      gap: "12px",
      "align-items": "center",
      "flex-wrap": "wrap",
      "margin-top": "8px",
    })}">
      ${buttonLink("Send Message", "mailto:info@mindflowdigital.com")}
      <span style="${styleString({
        "font-family": FONTS.body,
        "font-size": "13px",
        color: COLORS.muted,
      })}">Swap this visual form with a Divi Contact Form module after import if you need live submissions.</span>
    </div>`,
    {
      background: COLORS.white,
      padding: "28px",
    }
  );
}

function layoutRowsForCards(items, cardRenderer, columnsPerRow, fraction) {
  return chunk(items, columnsPerRow)
    .map((group, groupIndex) =>
      row(
        group
          .map((item, index) =>
            column(
              textModule(cardRenderer(item), `${item.title || item.step || item.label || "Card"} Card`),
              fraction,
              `${fraction} Column ${groupIndex + 1}-${index + 1}`
            )
          )
          .join(""),
        Array.from({ length: group.length }, () => fraction).join(","),
        `Cards Row ${groupIndex + 1}`
      )
    )
    .join("");
}

function buildHomeLayout(projects) {
  const featuredProjects = [
    projects.find((project) => project.slug === "social-media-campaign"),
    projects.find((project) => project.slug === "ecommerce-website-redesign"),
    projects.find((project) => project.slug === "ppc-performance-campaign"),
  ].filter(Boolean);

  return placeholder(
    [
      section(
        row(column(textModule(homeHeroHtml(), "Home Hero"), "4_4", "Hero Column"), "4_4", "Hero Row"),
        "Home Hero Section"
      ),
      section(
        [
          row(
            [
              column(
                textModule(
                  `<div id="about" style="${styleString({
                    position: "relative",
                    padding: "0.5rem 1rem 0.5rem 0",
                  })}">
                    ${imageHtml(assetUrl("about-creative.jpg"), "Digital MindFlow creative concept", {
                      height: "auto",
                      radius: "1.5rem",
                    })}
                    <span style="${styleString({
                      position: "absolute",
                      right: "-1rem",
                      bottom: "-1rem",
                      width: "6rem",
                      height: "6rem",
                      "border-radius": "1.5rem",
                      background: rgba(COLORS.accent, 0.2),
                      "z-index": "-1",
                    })}"></span>
                  </div>`,
                  "About Image"
                ),
                "1_2",
                "About Image Column"
              ),
              column(
                textModule(
                  `<div style="${styleString({
                    padding: "10px 0",
                  })}">
                    ${kicker("About Us")}
                    <h2 style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "clamp(32px, 4.5vw, 54px)",
                      "font-weight": "700",
                      "line-height": "1.15",
                      color: COLORS.foreground,
                      margin: "0 0 18px 0",
                    })}">We Are ${gradientText("Digital MindFlow")}</h2>
                    <p style="${styleString({
                      "font-family": FONTS.body,
                      "font-size": "17px",
                      "line-height": "1.8",
                      color: COLORS.muted,
                      "margin": "0 0 16px 0",
                    })}">A studio offering digital marketing services, specializing in consultation, social media, email marketing, website design and Google Ads for businesses, brands and individuals.</p>
                    <p style="${styleString({
                      "font-family": FONTS.body,
                      "font-size": "16px",
                      "line-height": "1.8",
                      color: COLORS.muted,
                      "margin": "0",
                    })}">We are professional, passionate, and strongly committed to what we do. With our experience, we aim to help our clients achieve their goals taking into account individual requirements and unique demands.</p>
                  </div>`,
                  "About Copy"
                ),
                "1_2",
                "About Copy Column"
              ),
            ].join(""),
            "1_2,1_2",
            "About Intro Row"
          ),
          layoutRowsForCards(ABOUT_VALUES, valueCardHtml, 3, "1_3"),
        ].join(""),
        "About Section"
      ),
      section(
        [
          row(
            column(
              textModule(
                `<div id="services" style="${styleString({
                  "text-align": "center",
                  padding: "14px 0 8px",
                })}">
                  ${kicker("What We Do")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "clamp(32px, 4.5vw, 54px)",
                    "font-weight": "700",
                    "line-height": "1.15",
                    color: COLORS.foreground,
                    margin: "0 0 18px 0",
                  })}">Our ${gradientText("Services")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.8",
                    color: COLORS.muted,
                    "max-width": "720px",
                    margin: "0 auto",
                  })}">We offer a full suite of digital marketing services to help your business thrive in the digital landscape.</p>
                </div>`,
                "Services Header"
              ),
              "4_4",
              "Services Header Column"
            ),
            "4_4",
            "Services Header Row"
          ),
          layoutRowsForCards(SERVICE_CARDS, serviceCardHtml, 3, "1_3"),
        ].join(""),
        "Services Section"
      ),
      section(
        [
          row(
            [
              column(
                textModule(
                  `<div style="${styleString({
                    padding: "10px 0",
                  })}">
                    ${kicker("Recent Work")}
                    <h2 style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "clamp(30px, 4vw, 52px)",
                      "font-weight": "700",
                      "line-height": "1.15",
                      color: COLORS.foreground,
                      margin: "0",
                    })}">Featured ${gradientText("Projects")}</h2>
                  </div>`,
                  "Featured Projects Header"
                ),
                "2_3",
                "Featured Header Column"
              ),
              column(
                textModule(
                  `<div style="${styleString({
                    display: "flex",
                    "justify-content": "flex-end",
                    "align-items": "center",
                    height: "100%",
                    padding: "16px 0",
                  })}">
                    <a href="/portfolio/" style="${styleString({
                      "font-family": FONTS.body,
                      "font-size": "15px",
                      "font-weight": "700",
                      color: COLORS.accentDeep,
                      "text-decoration": "none",
                    })}">View All Projects &rarr;</a>
                  </div>`,
                  "Featured Projects Link"
                ),
                "1_3",
                "Featured Link Column"
              ),
            ].join(""),
            "2_3,1_3",
            "Featured Projects Header Row"
          ),
          layoutRowsForCards(
            featuredProjects,
            featuredProjectPreviewHtml,
            3,
            "1_3"
          ),
        ].join(""),
        "Featured Projects Section"
      ),
      section(
        [
          row(
            column(
              textModule(
                `<div id="process" style="${styleString({
                  "text-align": "center",
                  padding: "14px 0 8px",
                })}">
                  ${kicker("How We Work")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "clamp(32px, 4.5vw, 54px)",
                    "font-weight": "700",
                    "line-height": "1.15",
                    color: COLORS.foreground,
                    margin: "0 0 18px 0",
                  })}">Our ${gradientText("Process")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.8",
                    color: COLORS.muted,
                    "max-width": "680px",
                    margin: "0 auto",
                  })}">A simple, proven three-step approach to driving real results for your business.</p>
                </div>`,
                "Process Header"
              ),
              "4_4",
              "Process Header Column"
            ),
            "4_4",
            "Process Header Row"
          ),
          layoutRowsForCards(PROCESS_STEPS, processCardHtml, 3, "1_3"),
        ].join(""),
        "Process Section"
      ),
      section(
        [
          row(
            column(
              textModule(
                `<div style="${styleString({
                  "text-align": "center",
                  padding: "14px 0 8px",
                })}">
                  ${kicker("Get In Touch")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "clamp(32px, 4.5vw, 54px)",
                    "font-weight": "700",
                    "line-height": "1.15",
                    color: COLORS.foreground,
                    margin: "0 0 18px 0",
                  })}">Let's ${gradientText("Work Together")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.8",
                    color: COLORS.muted,
                    "max-width": "720px",
                    margin: "0 auto",
                  })}">Ready to grow your online presence? Get in touch for a free consultation and let's discuss your goals.</p>
                </div>`,
                "Contact Header"
              ),
              "4_4",
              "Contact Header Column"
            ),
            "4_4",
            "Contact Header Row"
          ),
          row(
            [
              column(textModule(contactInfoHtml(), "Contact Info"), "2_5", "Contact Info Column"),
              column(textModule(contactFormHtml(), "Contact Form"), "3_5", "Contact Form Column"),
            ].join(""),
            "2_5,3_5",
            "Contact Content Row"
          ),
        ].join(""),
        "Contact Section"
      ),
    ].join("")
  );
}

function buildPortfolioLayout(projects) {
  return placeholder(
    [
      section(
        row(column(textModule(portfolioHeroHtml(), "Portfolio Hero"), "4_4", "Portfolio Hero Column"), "4_4", "Portfolio Hero Row"),
        "Portfolio Hero Section"
      ),
      section(
        [
          row(
            column(
              textModule(
                `<div style="${styleString({
                  "text-align": "center",
                  padding: "10px 0 4px",
                })}">
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.8",
                    color: COLORS.muted,
                    margin: "0 auto",
                    "max-width": "700px",
                  })}">A curated selection of campaigns, brand systems, websites, automation flows, and next-gen advertising work.</p>
                </div>`,
                "Portfolio Intro"
              ),
              "4_4",
              "Portfolio Intro Column"
            ),
            "4_4",
            "Portfolio Intro Row"
          ),
          layoutRowsForCards(projects, projectCardHtml, 2, "1_2"),
          row(
            column(
              textModule(
                `<div style="${styleString({
                  "text-align": "center",
                  padding: "18px 0 4px",
                })}">
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "18px",
                    color: COLORS.muted,
                    margin: "0 0 22px 0",
                  })}">Ready to become our next success story?</p>
                  ${buttonLink("Start Your Project", "/#contact")}
                </div>`,
                "Portfolio CTA"
              ),
              "4_4",
              "Portfolio CTA Column"
            ),
            "4_4",
            "Portfolio CTA Row"
          ),
        ].join(""),
        "Portfolio Projects Section"
      ),
    ].join("")
  );
}

function buildCaseStudyLayout(project, nextProject) {
  const resultCards = layoutRowsForCards(project.results, (result) => statCard(result.label, result.value), 4, "1_4");
  const approachHtml = `<div>
    ${project.approach
      .map(
        (item, index) =>
          `<div style="${styleString({
            display: "flex",
            gap: "14px",
            "align-items": "flex-start",
            "margin-bottom": "14px",
          })}">
            <span style="${styleString({
              display: "inline-flex",
              width: "32px",
              height: "32px",
              "align-items": "center",
              "justify-content": "center",
              "border-radius": "999px",
              background: COLORS.accent,
              color: COLORS.foreground,
              "font-family": FONTS.heading,
              "font-size": "14px",
              "font-weight": "700",
              "flex-shrink": "0",
            })}">${index + 1}</span>
            <span style="${styleString({
              "font-family": FONTS.body,
              "font-size": "16px",
              "line-height": "1.8",
              color: COLORS.muted,
            })}">${escapeHtml(item)}</span>
          </div>`
      )
      .join("")}
  </div>`;

  const testimonialHtml = project.testimonial
    ? contentCard(
        `<div style="${styleString({
          "font-family": FONTS.heading,
          "font-size": "40px",
          color: rgba(COLORS.accent, 0.36),
          "line-height": "1",
          "margin-bottom": "10px",
        })}">&ldquo;</div>
        <blockquote style="${styleString({
          "font-family": FONTS.heading,
          "font-size": "28px",
          "font-weight": "500",
          "line-height": "1.5",
          color: COLORS.white,
          margin: "0 0 18px 0",
        })}">${escapeHtml(project.testimonial.quote)}</blockquote>
        <div style="${styleString({
          "font-family": FONTS.body,
          "font-size": "15px",
          color: COLORS.white,
          "font-weight": "700",
        })}">${escapeHtml(project.testimonial.author)}</div>
        <div style="${styleString({
          "font-family": FONTS.body,
          "font-size": "14px",
          color: rgba(COLORS.white, 0.68),
          "margin-top": "4px",
        })}">${escapeHtml(project.testimonial.role)}</div>`,
        {
          background: COLORS.primary,
          borderColor: rgba(COLORS.white, 0.08),
          padding: "32px",
          shadow: "none",
        }
      )
    : "";

  return placeholder(
    [
      section(
        row(
          [
            column(textModule(caseStudyHeroLeftHtml(project), "Case Study Hero Copy"), "1_2", "Hero Copy Column"),
            column(
              textModule(
                `<div style="${styleString({
                  padding: "8px 0",
                })}">
                  ${imageHtml(assetUrl(project.imageFile), project.title, {
                    height: "420px",
                    radius: "28px",
                  })}
                </div>`,
                "Case Study Hero Image"
              ),
              "1_2",
              "Hero Image Column"
            ),
          ].join(""),
          "1_2,1_2",
          "Case Study Hero Row"
        ),
        "Case Study Hero Section"
      ),
      section(resultCards, "Case Study Results Bar"),
      section(
        [
          row(
            column(
              textModule(
                `<div style="${styleString({
                  padding: "10px 0",
                })}">
                  ${kicker("Project Overview")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "40px",
                    "font-weight": "700",
                    "line-height": "1.2",
                    color: COLORS.foreground,
                    margin: "0 0 16px 0",
                  })}">Project ${gradientText("Overview")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.9",
                    color: COLORS.muted,
                    margin: "0",
                  })}">${escapeHtml(project.overview)}</p>
                </div>`,
                "Overview Copy"
              ),
              "4_4",
              "Overview Column"
            ),
            "4_4",
            "Overview Row"
          ),
          row(
            column(
              textModule(
                `<div style="${styleString({
                  padding: "10px 0",
                })}">
                  ${kicker("The Challenge")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "40px",
                    "font-weight": "700",
                    "line-height": "1.2",
                    color: COLORS.foreground,
                    margin: "0 0 16px 0",
                  })}">The ${gradientText("Challenge")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.9",
                    color: COLORS.muted,
                    margin: "0",
                  })}">${escapeHtml(project.challenge)}</p>
                </div>`,
                "Challenge Copy"
              ),
              "4_4",
              "Challenge Column"
            ),
            "4_4",
            "Challenge Row"
          ),
          row(
            column(
              textModule(
                `<div style="${styleString({
                  padding: "10px 0",
                })}">
                  ${kicker("Our Approach")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "40px",
                    "font-weight": "700",
                    "line-height": "1.2",
                    color: COLORS.foreground,
                    margin: "0 0 18px 0",
                  })}">Our ${gradientText("Approach")}</h2>
                  ${approachHtml}
                </div>`,
                "Approach Copy"
              ),
              "4_4",
              "Approach Column"
            ),
            "4_4",
            "Approach Row"
          ),
          row(
            column(
              textModule(
                `<div style="${styleString({
                  padding: "10px 0 2px",
                })}">
                  ${kicker("The Results")}
                  <h2 style="${styleString({
                    "font-family": FONTS.heading,
                    "font-size": "40px",
                    "font-weight": "700",
                    "line-height": "1.2",
                    color: COLORS.foreground,
                    margin: "0 0 16px 0",
                  })}">The ${gradientText("Results")}</h2>
                  <p style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "17px",
                    "line-height": "1.9",
                    color: COLORS.muted,
                    margin: "0",
                  })}">${escapeHtml(project.outcome)}</p>
                </div>`,
                "Results Intro"
              ),
              "4_4",
              "Results Intro Column"
            ),
            "4_4",
            "Results Intro Row"
          ),
          resultCards,
          testimonialHtml
            ? row(
                column(textModule(testimonialHtml, "Case Study Testimonial"), "4_4", "Testimonial Column"),
                "4_4",
                "Testimonial Row"
              )
            : "",
          row(
            [
              column(
                textModule(
                  `<div style="${styleString({
                    padding: "12px 0",
                  })}">
                    <div style="${styleString({
                      "font-family": FONTS.body,
                      "font-size": "12px",
                      "font-weight": "700",
                      "text-transform": "uppercase",
                      "letter-spacing": "0.14em",
                      color: COLORS.muted,
                      "margin-bottom": "8px",
                    })}">Next Project</div>
                    <a href="${escapeAttr(CASE_STUDY_PAGE_PATH(nextProject.slug))}" style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "28px",
                      "font-weight": "700",
                      color: COLORS.foreground,
                      "text-decoration": "none",
                      "line-height": "1.25",
                    })}">${escapeHtml(nextProject.title)} &rarr;</a>
                  </div>`,
                  "Next Project"
                ),
                "1_2",
                "Next Project Column"
              ),
              column(
                textModule(
                  `<div style="${styleString({
                    display: "flex",
                    "justify-content": "flex-end",
                    "align-items": "center",
                    height: "100%",
                    padding: "18px 0",
                  })}">
                    ${buttonLink("Start Your Project", "/#contact")}
                  </div>`,
                  "Case Study CTA"
                ),
                "1_2",
                "Case Study CTA Column"
              ),
            ].join(""),
            "1_2,1_2",
            "Case Study Footer CTA Row"
          ),
        ].join(""),
        "Case Study Content Section"
      ),
    ].join("")
  );
}

function buildNotFoundLayout() {
  return placeholder(
    section(
      row(
        column(
          textModule(
            `<div style="${styleString({
              "min-height": "70vh",
              display: "flex",
              "align-items": "center",
              "justify-content": "center",
              padding: "120px 20px",
            })}">
              <div style="${styleString({
                background: COLORS.white,
                border: `1px solid ${COLORS.border}`,
                "border-radius": "28px",
                padding: "46px 34px",
                "text-align": "center",
                "max-width": "620px",
                width: "100%",
                "box-shadow": `0 24px 56px ${rgba(COLORS.primary, 0.08)}`,
              })}">
                <div style="${styleString({
                  "font-family": FONTS.heading,
                  "font-size": "72px",
                  "font-weight": "700",
                  color: COLORS.foreground,
                  "line-height": "1",
                  margin: "0 0 12px 0",
                })}">404</div>
                <h1 style="${styleString({
                  "font-family": FONTS.heading,
                  "font-size": "34px",
                  "font-weight": "700",
                  color: COLORS.foreground,
                  margin: "0 0 14px 0",
                })}">Oops! Page not found</h1>
                <p style="${styleString({
                  "font-family": FONTS.body,
                  "font-size": "16px",
                  "line-height": "1.8",
                  color: COLORS.muted,
                  margin: "0 0 22px 0",
                })}">The page you were looking for does not exist, was moved, or has not been created yet.</p>
                ${buttonLink("Return to Home", "/")}
              </div>
            </div>`,
            "404 Content"
          ),
          "4_4",
          "404 Column"
        ),
        "4_4",
        "404 Row"
      ),
      "404 Section"
    )
  );
}

function buildHeaderLayout() {
  return placeholder(
    section(
      row(
        column(menuModule("Primary Navigation"), "4_4", "Header Menu Column"),
        "4_4",
        "Header Row"
      ),
      "Global Header Section"
    )
  );
}

function buildFooterLayout() {
  const year = new Date().getFullYear();

  return placeholder(
    section(
      row(
        column(
          textModule(
            `<div style="${styleString({
              width: "100vw",
              "margin-left": "calc(50% - 50vw)",
              "margin-right": "calc(50% - 50vw)",
              background: COLORS.primary,
              color: COLORS.white,
              padding: "4rem 1.5rem 2rem",
            })}">
              <div style="${styleString({
                width: "100%",
                "max-width": "80rem",
                margin: "0 auto",
              })}">
                <div style="${styleString({
                  display: "grid",
                  "grid-template-columns": "repeat(auto-fit, minmax(min(100%, 14rem), 1fr))",
                  gap: "2.5rem",
                })}">
                  <div>
                    <div style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "1.25rem",
                      "font-weight": "700",
                      margin: "0 0 1rem 0",
                    })}">Digital ${gradientText("MindFlow")}</div>
                    <p style="${styleString({
                      "font-family": FONTS.body,
                      "font-size": "0.875rem",
                      "line-height": "1.8",
                      color: rgba(COLORS.white, 0.6),
                      margin: "0",
                      "max-width": "32rem",
                    })}">A studio offering strategic, modern and effective digital marketing solutions for businesses that want to grow their online presence.</p>
                  </div>
                  <div>
                    <h4 style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "0.875rem",
                      "font-weight": "600",
                      color: rgba(COLORS.white, 0.8),
                      "text-transform": "uppercase",
                      "letter-spacing": "0.1em",
                      margin: "0 0 1rem 0",
                    })}">Quick Links</h4>
                    <div style="${styleString({
                      display: "flex",
                      "flex-direction": "column",
                      gap: "0.625rem",
                    })}">
                      ${[
                        ["About", "/#about"],
                        ["Services", "/#services"],
                        ["Process", "/#process"],
                        ["Contact", "/#contact"],
                      ]
                        .map(
                          ([label, href]) =>
                            `<a href="${escapeAttr(href)}" style="${styleString({
                              "font-family": FONTS.body,
                              "font-size": "0.875rem",
                              color: rgba(COLORS.white, 0.6),
                              "text-decoration": "none",
                            })}">${escapeHtml(label)}</a>`
                        )
                        .join("")}
                    </div>
                  </div>
                  <div>
                    <h4 style="${styleString({
                      "font-family": FONTS.heading,
                      "font-size": "0.875rem",
                      "font-weight": "600",
                      color: rgba(COLORS.white, 0.8),
                      "text-transform": "uppercase",
                      "letter-spacing": "0.1em",
                      margin: "0 0 1rem 0",
                    })}">Contact</h4>
                    <div style="${styleString({
                      display: "flex",
                      "flex-direction": "column",
                      gap: "0.75rem",
                    })}">
                      <a href="mailto:info@mindflowdigital.com" style="${styleString({
                        "font-family": FONTS.body,
                        "font-size": "0.875rem",
                        color: rgba(COLORS.white, 0.6),
                        "text-decoration": "none",
                      })}">info@mindflowdigital.com</a>
                      <a href="tel:+35799882116" style="${styleString({
                        "font-family": FONTS.body,
                        "font-size": "0.875rem",
                        color: rgba(COLORS.white, 0.6),
                        "text-decoration": "none",
                      })}">+357 99 882116</a>
                      <div style="${styleString({
                        "font-family": FONTS.body,
                        "font-size": "0.875rem",
                        color: rgba(COLORS.white, 0.6),
                      })}">Paphos, Cyprus</div>
                    </div>
                  </div>
                </div>
                <div style="${styleString({
                  "margin-top": "3rem",
                  padding: "1.5rem 0 0",
                  "border-top": `1px solid ${rgba(COLORS.white, 0.1)}`,
                  display: "flex",
                  "justify-content": "space-between",
                  gap: "0.75rem",
                  "flex-wrap": "wrap",
                })}">
                  <span style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "0.75rem",
                    color: rgba(COLORS.white, 0.4),
                  })}">&copy; ${year} Digital MindFlow. All rights reserved.</span>
                  <span style="${styleString({
                    "font-family": FONTS.body,
                    "font-size": "0.75rem",
                    color: rgba(COLORS.white, 0.4),
                  })}">Marketing Services &middot; Paphos, Cyprus</span>
                </div>
              </div>
            </div>`,
            "Footer Content"
          ),
          "4_4",
          "Footer Column"
        ),
        "4_4",
        "Footer Row"
      ),
      "Global Footer Section"
    )
  );
}

function builderExport(postId, content, imageFiles) {
  return {
    context: "et_builder",
    data: {
      [String(postId)]: content,
    },
    presets: [],
    global_colors: DIVI_GLOBAL_COLORS.map(([id, data]) => [id, { ...data }]),
    global_variables: DIVI_GLOBAL_VARIABLES.map((variable) => ({ ...variable })),
    page_settings_meta: {},
    canvases: {},
    images: imageEntries(imageFiles),
    thumbnails: {},
  };
}

function themeBuilderLayoutEntry(postId, postTitle, postType, content, imageFiles) {
  return {
    context: "et_builder",
    data: {
      [String(postId)]: content,
    },
    images: imageEntries(imageFiles),
    thumbnails: {},
    post_title: postTitle,
    post_type: postType,
    theme_builder: {
      is_global: false,
    },
    post_meta: [
      {
        key: "_et_pb_use_builder",
        value: "on",
      },
      {
        key: "_et_pb_use_divi_5",
        value: "on",
      },
    ],
  };
}

function imageEntries(imageFiles) {
  const entries = {};
  for (const fileName of [...new Set(imageFiles)].sort()) {
    const filePath = path.join(ASSETS_DIR, fileName);
    const url = assetUrl(fileName);
    if (!fs.existsSync(filePath)) {
      continue;
    }
    const encoded = fs.readFileSync(filePath).toString("base64");
    entries[url] = {
      encoded,
      url,
    };
  }
  return entries;
}

function writeJson(fileName, payload) {
  fs.mkdirSync(EXPORTS_DIR, { recursive: true });
  fs.writeFileSync(path.join(EXPORTS_DIR, fileName), `${JSON.stringify(payload, null, 2)}\n`);
}

function writeText(fileName, content) {
  fs.mkdirSync(EXPORTS_DIR, { recursive: true });
  fs.writeFileSync(path.join(EXPORTS_DIR, fileName), content);
}

function loadProjects() {
  const source = fs.readFileSync(PROJECTS_FILE, "utf8");
  const importMatches = [...source.matchAll(/^import\s+(\w+)\s+from\s+"@\/assets\/([^"]+)";$/gm)];
  const assetByVariable = Object.fromEntries(
    importMatches.map(([, variableName, fileName]) => [variableName, fileName])
  );
  const projectsMatch = source.match(/export const projects: Project\[\] = (\[[\s\S]*\]);/m);

  if (!projectsMatch) {
    throw new Error("Unable to locate projects array in src/data/projects.ts");
  }

  let executableSource = projectsMatch[1];
  for (const [variableName, fileName] of Object.entries(assetByVariable)) {
    executableSource = executableSource.replace(
      new RegExp(`\\b${variableName}\\b`, "g"),
      JSON.stringify(fileName)
    );
  }

  const projects = Function(`return (${executableSource});`)();
  return projects.map((project) => ({
    ...project,
    imageFile: project.image,
  }));
}

function createExports() {
  const projects = loadProjects();

  const homeLayout = buildHomeLayout(projects);
  const portfolioLayout = buildPortfolioLayout(projects);
  const notFoundLayout = buildNotFoundLayout();
  const headerLayout = buildHeaderLayout();
  const footerLayout = buildFooterLayout();

  writeJson(
    "layout-home.json",
    builderExport(51001, homeLayout, [
      "hero-bg.jpg",
      "about-creative.jpg",
      "portfolio-social.jpg",
      "portfolio-web.jpg",
      "portfolio-ads.jpg",
    ])
  );

  writeJson(
    "layout-portfolio.json",
    builderExport(
      51002,
      portfolioLayout,
      projects.map((project) => project.imageFile)
    )
  );

  writeJson("layout-404.json", builderExport(51003, notFoundLayout, []));
  writeJson("layout-global-header.json", builderExport(51004, headerLayout, ["dm-logo.jpg"]));
  writeJson("layout-global-footer.json", builderExport(51005, footerLayout, []));

  projects.forEach((project, index) => {
    const nextProject = projects[(index + 1) % projects.length];
    const caseStudyLayout = buildCaseStudyLayout(project, nextProject);
    writeJson(
      `layout-case-study-${project.slug}.json`,
      builderExport(52000 + index, caseStudyLayout, [project.imageFile])
    );
  });

  writeJson("theme-builder-global-header-footer.json", {
    context: "et_theme_builder",
    templates: [
      {
        title: "Digital MindFlow Global Chrome",
        autogenerated_title: false,
        default: true,
        enabled: true,
        use_on: [],
        exclude_from: [],
        layouts: {
          header: {
            id: 61001,
            enabled: true,
          },
          body: {
            id: 0,
            enabled: false,
          },
          footer: {
            id: 61002,
            enabled: true,
          },
        },
      },
    ],
    layouts: {
      61001: themeBuilderLayoutEntry(
        61001,
        "Digital MindFlow Global Header",
        "et_header_layout",
        headerLayout,
        ["dm-logo.jpg"]
      ),
      61002: themeBuilderLayoutEntry(
        61002,
        "Digital MindFlow Global Footer",
        "et_footer_layout",
        footerLayout,
        []
      ),
    },
    presets: [],
    global_colors: DIVI_GLOBAL_COLORS.map(([id, data]) => [id, { ...data }]),
    global_variables: DIVI_GLOBAL_VARIABLES.map((variable) => ({ ...variable })),
    has_default_template: true,
    has_global_layouts: false,
  });

  writeText(
    "README.md",
    `# Divi 5 Imports

Use \`theme-builder-global-header-footer.json\` in Divi Theme Builder if you want the global header and footer assigned in one import.

Use the \`layout-*.json\` files inside the Divi 5 builder portability modal for each page. The standalone \`layout-global-header.json\` and \`layout-global-footer.json\` files are included if you prefer to import those layouts manually into Theme Builder areas.

All exports use the Divi 5 block format (\`wp:divi/*\`) and do not use legacy Divi shortcode layouts.

Suggested page slugs:

- Home: site front page
- Portfolio: \`portfolio\`
- 404: assign the imported layout to your not-found experience as needed
- Case study: \`brand-strategy-identity\`
- Case study: \`social-media-campaign\`
- Case study: \`ecommerce-website-redesign\`
- Case study: \`ppc-performance-campaign\`
- Case study: \`email-automation-system\`
- Case study: \`ai-powered-ad-campaign\`

Notes:

- Asset files are embedded in each JSON export, so Divi should upload and relink them during import.
- Internal links assume the homepage is the site root and the portfolio page uses \`/portfolio/\`.
- Shared Divi 5 variables for colors, fonts, spacing, and radii are included in each import payload.
- The global header uses the Divi 5 Menu block and resolves against the normal WordPress \`primary-menu\` location.
- Layout styling uses responsive \`rem\`, \`clamp()\`, \`calc()\`, \`var(--gcid-...)\`, and \`var(--gvid-...)\` references instead of hardcoded theme values.
- The contact section includes a visual form replica. Replace it with a Divi Contact Form module after import if you need live submissions.
`
  );
}

createExports();
