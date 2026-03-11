import { motion, useInView } from "framer-motion";
import { useRef } from "react";
import { Link } from "react-router-dom";
import { ArrowRight } from "lucide-react";

import portfolioSocial from "@/assets/portfolio-social.jpg";
import portfolioWeb from "@/assets/portfolio-web.jpg";
import portfolioAds from "@/assets/portfolio-ads.jpg";

const featured = [
  {
    image: portfolioSocial,
    title: "Social Media Campaign",
    client: "Artisan Food Brand",
    category: "Social Media Marketing",
  },
  {
    image: portfolioWeb,
    title: "E-Commerce Website Redesign",
    client: "Fashion Retailer",
    category: "Web Design",
  },
  {
    image: portfolioAds,
    title: "PPC Performance Campaign",
    client: "Real Estate Agency",
    category: "PPC & Google Ads",
  },
];

const PortfolioPreview = () => {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: "-80px" });

  return (
    <section className="py-24 lg:py-32 bg-background">
      <div ref={ref} className="container mx-auto px-6 lg:px-8">
        {/* Header */}
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={isInView ? { opacity: 1, y: 0 } : {}}
          transition={{ duration: 0.6 }}
          className="flex flex-col sm:flex-row items-start sm:items-end justify-between gap-6 mb-12"
        >
          <div>
            <span className="text-sm font-semibold tracking-widest uppercase text-accent mb-4 block">
              Recent Work
            </span>
            <h2 className="font-heading text-3xl sm:text-4xl lg:text-5xl font-bold leading-tight">
              Featured <span className="text-gradient-accent">Projects</span>
            </h2>
          </div>
          <Link
            to="/portfolio"
            className="inline-flex items-center gap-2 text-sm font-semibold text-accent hover:underline transition-all group"
          >
            View All Projects
            <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
          </Link>
        </motion.div>

        {/* Grid */}
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
          {featured.map((project, i) => (
            <motion.div
              key={project.title}
              initial={{ opacity: 0, y: 30 }}
              animate={isInView ? { opacity: 1, y: 0 } : {}}
              transition={{ duration: 0.5, delay: 0.15 + i * 0.1 }}
              className="group cursor-pointer"
            >
              <Link to="/portfolio">
                <div className="relative overflow-hidden rounded-2xl aspect-[4/3] mb-4">
                  <img
                    src={project.image}
                    alt={project.title}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    loading="lazy"
                  />
                  <div className="absolute inset-0 bg-primary/0 group-hover:bg-primary/40 transition-colors duration-300 flex items-center justify-center">
                    <ArrowRight className="w-8 h-8 text-primary-foreground opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
                  </div>
                  <div className="absolute top-3 left-3">
                    <span className="inline-block rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground">
                      {project.category}
                    </span>
                  </div>
                </div>
                <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-1">
                  {project.client}
                </p>
                <h3 className="font-heading text-lg font-semibold group-hover:text-accent transition-colors">
                  {project.title}
                </h3>
              </Link>
            </motion.div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default PortfolioPreview;
