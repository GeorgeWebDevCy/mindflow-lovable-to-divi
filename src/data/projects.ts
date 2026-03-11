import portfolioSocial from "@/assets/portfolio-social.jpg";
import portfolioWeb from "@/assets/portfolio-web.jpg";
import portfolioAds from "@/assets/portfolio-ads.jpg";
import portfolioEmail from "@/assets/portfolio-email.jpg";
import portfolioBrand from "@/assets/portfolio-brand.jpg";
import portfolioAi from "@/assets/portfolio-ai.jpg";

export interface Project {
  slug: string;
  image: string;
  title: string;
  client: string;
  category: string;
  services: string[];
  goal: string;
  outcome: string;
  // Case study details
  overview: string;
  challenge: string;
  approach: string[];
  results: { label: string; value: string }[];
  testimonial?: { quote: string; author: string; role: string };
  timeline: string;
}

export const projects: Project[] = [
  {
    slug: "brand-strategy-identity",
    image: portfolioBrand,
    title: "Brand Strategy & Identity",
    client: "Luxury Boutique Hotel",
    category: "Consultation",
    services: ["Brand Positioning", "Competitive Analysis", "Tone of Voice"],
    goal: "Establish a premium brand identity that resonates with high-end travelers.",
    outcome: "40% increase in brand awareness and 25% growth in direct bookings within 6 months.",
    overview:
      "A luxury boutique hotel in the Mediterranean sought to differentiate itself in an increasingly competitive hospitality market. They needed a brand identity that communicated exclusivity, warmth, and authentic local experiences to attract discerning international travelers.",
    challenge:
      "The hotel had strong on-site experiences but lacked a cohesive brand narrative. Their digital presence was fragmented, with inconsistent messaging across channels, resulting in low brand recall and over-reliance on third-party booking platforms.",
    approach: [
      "Conducted in-depth competitor analysis across 15 luxury hotels in the region to identify positioning gaps.",
      "Ran guest surveys and stakeholder workshops to uncover the hotel's unique value propositions.",
      "Developed a comprehensive brand book including visual identity, tone of voice guidelines, and messaging framework.",
      "Created a content strategy aligned with the new brand positioning across all digital touchpoints.",
    ],
    results: [
      { label: "Brand Awareness", value: "+40%" },
      { label: "Direct Bookings", value: "+25%" },
      { label: "Social Engagement", value: "+120%" },
      { label: "Guest Satisfaction", value: "4.9/5" },
    ],
    testimonial: {
      quote: "Digital MindFlow transformed how we communicate our story. Our guests now feel the brand promise before they even arrive.",
      author: "Elena K.",
      role: "General Manager, Luxury Boutique Hotel",
    },
    timeline: "3 months",
  },
  {
    slug: "social-media-campaign",
    image: portfolioSocial,
    title: "Social Media Campaign",
    client: "Artisan Food Brand",
    category: "Social Media Marketing",
    services: ["Content Strategy", "Instagram & Facebook Management", "Influencer Marketing"],
    goal: "Build an engaged community and drive online sales through social channels.",
    outcome: "300% follower growth, 5x engagement rate improvement, and 180% increase in social-driven revenue.",
    overview:
      "An artisan food brand with exceptional products was struggling to translate their quality into digital engagement. They needed a social media strategy that would build a loyal community and convert followers into customers.",
    challenge:
      "Despite having premium, handcrafted products, the brand's social presence was minimal. Inconsistent posting, generic content, and zero influencer partnerships meant they were invisible to their ideal audience of food enthusiasts and conscious consumers.",
    approach: [
      "Developed a content calendar with a mix of behind-the-scenes, recipe content, and lifestyle imagery.",
      "Partnered with 12 micro-influencers in the food and wellness space for authentic product placement.",
      "Implemented a UGC (user-generated content) program encouraging customers to share their experiences.",
      "Launched targeted paid campaigns optimized for engagement and website traffic.",
    ],
    results: [
      { label: "Follower Growth", value: "+300%" },
      { label: "Engagement Rate", value: "5x" },
      { label: "Social Revenue", value: "+180%" },
      { label: "UGC Posts", value: "500+" },
    ],
    testimonial: {
      quote: "Our social channels went from quiet to buzzing. The community they built for us is now our most valuable marketing asset.",
      author: "Marco D.",
      role: "Founder, Artisan Food Brand",
    },
    timeline: "6 months",
  },
  {
    slug: "ecommerce-website-redesign",
    image: portfolioWeb,
    title: "E-Commerce Website Redesign",
    client: "Fashion Retailer",
    category: "Web Design",
    services: ["Web Redesign", "SEO", "Content Creation", "Testing & Launch"],
    goal: "Modernize the online store experience and improve conversion rates.",
    outcome: "65% improvement in page load speed, 45% increase in conversion rate, 2x average session duration.",
    overview:
      "A fashion retailer with a growing physical presence needed their online store to match the experience customers had in-store. The existing website was outdated, slow, and failing to convert the significant traffic they were receiving.",
    challenge:
      "The legacy website suffered from poor mobile responsiveness, slow load times, and a checkout flow that caused 70% cart abandonment. The brand's premium positioning was undermined by a digital experience that felt dated.",
    approach: [
      "Performed comprehensive UX audit with heatmap analysis and user testing sessions.",
      "Designed a mobile-first experience with emphasis on visual storytelling and product photography.",
      "Rebuilt the checkout flow reducing steps from 5 to 2, with guest checkout and multiple payment options.",
      "Implemented technical SEO improvements including Core Web Vitals optimization and structured data.",
    ],
    results: [
      { label: "Page Speed", value: "+65%" },
      { label: "Conversion Rate", value: "+45%" },
      { label: "Session Duration", value: "2x" },
      { label: "Cart Abandonment", value: "-40%" },
    ],
    testimonial: {
      quote: "The new website doesn't just look beautiful—it performs. Our online revenue has nearly doubled since the redesign.",
      author: "Sophie L.",
      role: "E-Commerce Director, Fashion Retailer",
    },
    timeline: "4 months",
  },
  {
    slug: "ppc-performance-campaign",
    image: portfolioAds,
    title: "PPC Performance Campaign",
    client: "Real Estate Agency",
    category: "PPC & Google Ads",
    services: ["Google Ads", "Audience Targeting", "Conversion Tracking", "Analytics"],
    goal: "Generate high-quality leads and maximize return on ad spend.",
    outcome: "4.5x ROAS, 200+ qualified leads per month, 35% lower cost-per-acquisition.",
    overview:
      "A growing real estate agency needed to scale their lead generation while maintaining lead quality. They had tried running ads in-house but were burning budget without seeing meaningful results.",
    challenge:
      "Previous PPC efforts had a ROAS of just 1.2x with leads that rarely converted to viewings. The campaigns lacked proper tracking, audience segmentation, and landing page optimization, resulting in wasted spend and frustrated sales teams.",
    approach: [
      "Set up comprehensive conversion tracking including phone calls, form submissions, and property viewing bookings.",
      "Created hyper-targeted audience segments based on property type, budget range, and buyer intent signals.",
      "Developed dedicated landing pages for each property category with clear CTAs and social proof.",
      "Implemented automated bid strategies with weekly optimization cycles and A/B testing on ad copy.",
    ],
    results: [
      { label: "ROAS", value: "4.5x" },
      { label: "Monthly Leads", value: "200+" },
      { label: "Cost per Lead", value: "-35%" },
      { label: "Lead-to-Sale", value: "+60%" },
    ],
    testimonial: {
      quote: "The quality of leads we get now is incredible. Our sales team is closing deals instead of chasing dead ends.",
      author: "Andreas P.",
      role: "Managing Director, Real Estate Agency",
    },
    timeline: "Ongoing (8+ months)",
  },
  {
    slug: "email-automation-system",
    image: portfolioEmail,
    title: "Email Automation System",
    client: "SaaS Platform",
    category: "Email Marketing",
    services: ["List Segmentation", "Email Automation", "A/B Testing", "Analytics"],
    goal: "Build a nurture sequence that converts trial users into paying customers.",
    outcome: "52% open rate, 28% click-through rate, 3x improvement in trial-to-paid conversion.",
    overview:
      "A B2B SaaS platform with a growing user base had a leaky conversion funnel. Trial users were signing up but not converting to paid plans. They needed an email automation system that would educate, engage, and convert.",
    challenge:
      "The platform had a 14-day free trial but only 4% of trial users converted. There was no onboarding email sequence, no segmentation, and the only communication was a generic expiry reminder that felt impersonal and pushy.",
    approach: [
      "Mapped the entire user journey from signup to conversion, identifying key activation milestones.",
      "Created a 10-email nurture sequence triggered by user behavior and feature adoption patterns.",
      "Implemented dynamic segmentation based on user role, company size, and engagement level.",
      "Set up comprehensive A/B testing on subject lines, send times, and content formats.",
    ],
    results: [
      { label: "Open Rate", value: "52%" },
      { label: "Click-Through", value: "28%" },
      { label: "Trial Conversion", value: "3x" },
      { label: "Churn Reduction", value: "-22%" },
    ],
    testimonial: {
      quote: "The automated sequences feel personal and timely. Our trial conversion jumped from 4% to 12% in just two months.",
      author: "David R.",
      role: "Head of Growth, SaaS Platform",
    },
    timeline: "2 months",
  },
  {
    slug: "ai-powered-ad-campaign",
    image: portfolioAi,
    title: "AI-Powered Ad Campaign",
    client: "Tech Startup",
    category: "AI-Powered Advertising",
    services: ["LLM Ads", "GEO Optimization", "AI-Generated Creatives", "Programmatic Ads"],
    goal: "Leverage next-gen AI advertising channels to reach early adopters at scale.",
    outcome: "Presence across ChatGPT & Perplexity, 60% lower CPA compared to traditional channels.",
    overview:
      "A tech startup building developer tools wanted to be among the first to advertise through AI-powered channels. They recognized that their target audience of developers and tech professionals were increasingly using AI assistants for research and recommendations.",
    challenge:
      "Traditional advertising channels were becoming saturated and expensive for developer-focused products. The startup needed to find innovative channels where their audience was already spending time—and AI platforms were the emerging frontier.",
    approach: [
      "Researched and mapped the emerging AI advertising ecosystem including LLM-integrated ads and AI search optimization.",
      "Developed GEO (Generative Engine Optimization) strategy to ensure the brand appeared in AI-generated recommendations.",
      "Created AI-native ad creatives optimized for conversational contexts and programmatic AI placements.",
      "Set up attribution tracking for AI-driven traffic and conversions with custom UTM frameworks.",
    ],
    results: [
      { label: "AI Platform Reach", value: "2M+" },
      { label: "CPA vs Traditional", value: "-60%" },
      { label: "Brand Mentions", value: "+340%" },
      { label: "Developer Signups", value: "+85%" },
    ],
    testimonial: {
      quote: "Being early to AI advertising gave us a massive competitive advantage. Digital MindFlow made us visible where our users actually discover tools.",
      author: "Lisa T.",
      role: "CMO, Tech Startup",
    },
    timeline: "4 months",
  },
];
