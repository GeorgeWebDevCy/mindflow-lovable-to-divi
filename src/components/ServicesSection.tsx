import { motion, useInView } from "framer-motion";
import { useRef } from "react";
import {
  Lightbulb,
  Share2,
  Mail,
  Search,
  MousePointerClick,
  Globe,
  BrainCircuit,
  GraduationCap,
  Bot,
} from "lucide-react";

const services = [
  {
    icon: Lightbulb,
    title: "Consultation",
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
    icon: Share2,
    title: "Social Media Marketing",
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
    icon: Mail,
    title: "Email Marketing",
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
    icon: Search,
    title: "SEO",
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
    icon: MousePointerClick,
    title: "PPC & Google Ads",
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
    icon: Globe,
    title: "Web Design",
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
    icon: BrainCircuit,
    title: "AI-Powered Advertising",
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
    icon: GraduationCap,
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
    icon: Bot,
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

const ServicesSection = () => {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: "-80px" });

  return (
    <section id="services" className="py-24 lg:py-32 bg-card">
      <div ref={ref} className="container mx-auto px-6 lg:px-8">
        {/* Header */}
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={isInView ? { opacity: 1, y: 0 } : {}}
          transition={{ duration: 0.6 }}
          className="max-w-3xl mx-auto text-center mb-16"
        >
          <span className="text-sm font-semibold tracking-widest uppercase text-accent mb-4 block">
            What We Do
          </span>
          <h2 className="font-heading text-3xl sm:text-4xl lg:text-5xl font-bold leading-tight mb-6">
            Our <span className="text-gradient-accent">Services</span>
          </h2>
          <p className="text-lg text-muted-foreground leading-relaxed">
            We offer a full suite of digital marketing services to help your
            business thrive in the digital landscape.
          </p>
        </motion.div>

        {/* Services grid */}
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
          {services.map((service, i) => (
            <motion.div
              key={service.title}
              initial={{ opacity: 0, y: 30 }}
              animate={isInView ? { opacity: 1, y: 0 } : {}}
              transition={{ duration: 0.5, delay: 0.1 + i * 0.1 }}
              className="group relative p-8 rounded-2xl bg-background border border-border hover:border-accent/30 hover:shadow-elevated transition-all duration-300"
            >
              <div className="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-accent/10 text-accent mb-5 group-hover:bg-accent group-hover:text-accent-foreground transition-colors duration-300">
                <service.icon className="w-5 h-5" />
              </div>
              <h3 className="font-heading text-lg font-semibold mb-2">
                {service.title}
              </h3>
              <p className="text-sm text-muted-foreground leading-relaxed mb-4">
                {service.description}
              </p>
              <ul className="space-y-2">
                {service.items.map((item) => (
                  <li
                    key={item}
                    className="flex items-start gap-2 text-sm text-foreground/80"
                  >
                    <span className="w-1.5 h-1.5 rounded-full bg-accent mt-2 flex-shrink-0" />
                    {item}
                  </li>
                ))}
              </ul>
            </motion.div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default ServicesSection;
