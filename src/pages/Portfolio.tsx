import { motion, useInView } from "framer-motion";
import { useRef } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, ExternalLink } from "lucide-react";
import Navbar from "@/components/Navbar";
import Footer from "@/components/Footer";

import portfolioSocial from "@/assets/portfolio-social.jpg";
import portfolioWeb from "@/assets/portfolio-web.jpg";
import portfolioAds from "@/assets/portfolio-ads.jpg";
import portfolioEmail from "@/assets/portfolio-email.jpg";
import portfolioBrand from "@/assets/portfolio-brand.jpg";
import portfolioAi from "@/assets/portfolio-ai.jpg";

const projects = [
  {
    image: portfolioBrand,
    title: "Brand Strategy & Identity",
    client: "Luxury Boutique Hotel",
    category: "Consultation",
    services: ["Brand Positioning", "Competitive Analysis", "Tone of Voice"],
    goal: "Establish a premium brand identity that resonates with high-end travelers.",
    outcome: "40% increase in brand awareness and 25% growth in direct bookings within 6 months.",
  },
  {
    image: portfolioSocial,
    title: "Social Media Campaign",
    client: "Artisan Food Brand",
    category: "Social Media Marketing",
    services: ["Content Strategy", "Instagram & Facebook Management", "Influencer Marketing"],
    goal: "Build an engaged community and drive online sales through social channels.",
    outcome: "300% follower growth, 5x engagement rate improvement, and 180% increase in social-driven revenue.",
  },
  {
    image: portfolioWeb,
    title: "E-Commerce Website Redesign",
    client: "Fashion Retailer",
    category: "Web Design",
    services: ["Web Redesign", "SEO", "Content Creation", "Testing & Launch"],
    goal: "Modernize the online store experience and improve conversion rates.",
    outcome: "65% improvement in page load speed, 45% increase in conversion rate, 2x average session duration.",
  },
  {
    image: portfolioAds,
    title: "PPC Performance Campaign",
    client: "Real Estate Agency",
    category: "PPC & Google Ads",
    services: ["Google Ads", "Audience Targeting", "Conversion Tracking", "Analytics"],
    goal: "Generate high-quality leads and maximize return on ad spend.",
    outcome: "4.5x ROAS, 200+ qualified leads per month, 35% lower cost-per-acquisition.",
  },
  {
    image: portfolioEmail,
    title: "Email Automation System",
    client: "SaaS Platform",
    category: "Email Marketing",
    services: ["List Segmentation", "Email Automation", "A/B Testing", "Analytics"],
    goal: "Build a nurture sequence that converts trial users into paying customers.",
    outcome: "52% open rate, 28% click-through rate, 3x improvement in trial-to-paid conversion.",
  },
  {
    image: portfolioAi,
    title: "AI-Powered Ad Campaign",
    client: "Tech Startup",
    category: "AI-Powered Advertising",
    services: ["LLM Ads", "GEO Optimization", "AI-Generated Creatives", "Programmatic Ads"],
    goal: "Leverage next-gen AI advertising channels to reach early adopters at scale.",
    outcome: "Presence across ChatGPT & Perplexity, 60% lower CPA compared to traditional channels.",
  },
];

const ProjectCard = ({
  project,
  index,
}: {
  project: (typeof projects)[0];
  index: number;
}) => {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: "-60px" });

  return (
    <motion.div
      ref={ref}
      initial={{ opacity: 0, y: 40 }}
      animate={isInView ? { opacity: 1, y: 0 } : {}}
      transition={{ duration: 0.6, delay: index * 0.1 }}
      className="group bg-card border border-border rounded-2xl overflow-hidden hover:shadow-elevated transition-all duration-300"
    >
      {/* Image */}
      <div className="relative overflow-hidden aspect-[4/3]">
        <img
          src={project.image}
          alt={project.title}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          loading="lazy"
        />
        <div className="absolute top-4 left-4">
          <span className="inline-block rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground">
            {project.category}
          </span>
        </div>
      </div>

      {/* Content */}
      <div className="p-6 lg:p-8">
        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">
          {project.client}
        </p>
        <h3 className="font-heading text-xl font-semibold mb-3">
          {project.title}
        </h3>

        {/* Services */}
        <div className="flex flex-wrap gap-2 mb-4">
          {project.services.map((service) => (
            <span
              key={service}
              className="inline-block rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
            >
              {service}
            </span>
          ))}
        </div>

        {/* Goal & Outcome */}
        <div className="space-y-3 mb-5">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-accent mb-1">
              Goal
            </p>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {project.goal}
            </p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-accent mb-1">
              Outcome
            </p>
            <p className="text-sm text-foreground leading-relaxed font-medium">
              {project.outcome}
            </p>
          </div>
        </div>

        <button className="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline transition-all">
          View Case Study
          <ExternalLink className="w-3.5 h-3.5" />
        </button>
      </div>
    </motion.div>
  );
};

const Portfolio = () => {
  const headerRef = useRef(null);
  const headerInView = useInView(headerRef, { once: true });

  return (
    <div className="min-h-screen bg-background">
      <Navbar />

      {/* Hero */}
      <section className="pt-32 pb-16 lg:pt-40 lg:pb-20 bg-primary">
        <div className="container mx-auto px-6 lg:px-8 text-center">
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7 }}
          >
            <span className="text-sm font-semibold tracking-widest uppercase text-accent mb-4 block">
              Our Work
            </span>
            <h1 className="font-heading text-4xl sm:text-5xl lg:text-6xl font-bold text-primary-foreground leading-tight mb-6">
              Recent <span className="text-gradient-accent">Projects</span>
            </h1>
            <p className="text-lg text-primary-foreground/70 max-w-2xl mx-auto leading-relaxed">
              Explore our portfolio of successful campaigns and projects. Each
              case study showcases our strategic approach and measurable results.
            </p>
          </motion.div>
        </div>
      </section>

      {/* Projects grid */}
      <section className="py-20 lg:py-28">
        <div ref={headerRef} className="container mx-auto px-6 lg:px-8">
          <div className="grid md:grid-cols-2 gap-8">
            {projects.map((project, i) => (
              <ProjectCard key={project.title} project={project} index={i} />
            ))}
          </div>

          {/* CTA */}
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={headerInView ? { opacity: 1, y: 0 } : {}}
            transition={{ duration: 0.6, delay: 0.5 }}
            className="text-center mt-16"
          >
            <p className="text-lg text-muted-foreground mb-6">
              Ready to become our next success story?
            </p>
            <Link
              to="/"
              onClick={() => {
                setTimeout(() => {
                  document.querySelector("#contact")?.scrollIntoView({ behavior: "smooth" });
                }, 100);
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-accent px-8 py-4 text-base font-semibold text-accent-foreground shadow-accent-glow hover:opacity-90 transition-opacity"
            >
              Start Your Project
              <ArrowRight className="w-4 h-4" />
            </Link>
          </motion.div>
        </div>
      </section>

      <Footer />
    </div>
  );
};

export default Portfolio;
