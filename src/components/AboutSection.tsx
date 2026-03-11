import { motion } from "framer-motion";
import { useInView } from "framer-motion";
import { useRef } from "react";
import { Target, Eye, Handshake } from "lucide-react";
import aboutCreative from "@/assets/about-creative.jpg";

const values = [
  {
    icon: Target,
    title: "Our Mission",
    text: "To develop trusted business partnerships by providing the highest level of digital marketing services that contribute to our client's growth, success, and the community's development.",
  },
  {
    icon: Eye,
    title: "Our Vision",
    text: "Our team consists of highly skilled professionals who are passionate about what they do. We believe that if you communicate with people right, you can gain excellence.",
  },
  {
    icon: Handshake,
    title: "Our Approach",
    text: "Through creative and customized strategy, we meet your business expectations. We use the latest tools, trends, and the appropriate platforms for your brand to achieve the best results.",
  },
];

const AboutSection = () => {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: "-100px" });

  return (
    <section id="about" className="py-24 lg:py-32 bg-background">
      <div ref={ref} className="container mx-auto px-6 lg:px-8">
        {/* Two-column layout: image + text */}
        <div className="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center mb-20">
          {/* Image */}
          <motion.div
            initial={{ opacity: 0, x: -30 }}
            animate={isInView ? { opacity: 1, x: 0 } : {}}
            transition={{ duration: 0.7 }}
            className="relative"
          >
            <div className="rounded-2xl overflow-hidden shadow-elevated">
              <img
                src={aboutCreative}
                alt="Digital MindFlow creative concept — face emerging from ethereal clouds"
                className="w-full h-auto object-cover aspect-square"
                loading="lazy"
              />
            </div>
            {/* Decorative accent */}
            <div className="absolute -bottom-4 -right-4 w-24 h-24 rounded-2xl bg-accent/20 -z-10" />
          </motion.div>

          {/* Text */}
          <motion.div
            initial={{ opacity: 0, x: 30 }}
            animate={isInView ? { opacity: 1, x: 0 } : {}}
            transition={{ duration: 0.7, delay: 0.1 }}
          >
            <span className="text-sm font-semibold tracking-widest uppercase text-accent mb-4 block">
              About Us
            </span>
            <h2 className="font-heading text-3xl sm:text-4xl lg:text-5xl font-bold leading-tight mb-6">
              We Are{" "}
              <span className="text-gradient-accent">Digital MindFlow</span>
            </h2>
            <p className="text-lg text-muted-foreground leading-relaxed mb-4">
              A studio offering digital marketing services, specializing in
              consultation, social media, email marketing, website design and
              Google Ads for businesses, brands and individuals.
            </p>
            <p className="text-muted-foreground leading-relaxed">
              We are professional, passionate, and strongly committed to what we
              do. With our experience, we aim to help our clients achieve their
              goals taking into account individual requirements and unique
              demands.
            </p>
          </motion.div>
        </div>

        {/* Values grid */}
        <div className="grid md:grid-cols-3 gap-8">
          {values.map((item, i) => (
            <motion.div
              key={item.title}
              initial={{ opacity: 0, y: 30 }}
              animate={isInView ? { opacity: 1, y: 0 } : {}}
              transition={{ duration: 0.6, delay: 0.3 + i * 0.15 }}
              className="group text-center p-8 rounded-2xl bg-card border border-border hover:shadow-elevated transition-all duration-300"
            >
              <div className="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-accent/10 text-accent mb-6 group-hover:bg-accent group-hover:text-accent-foreground transition-colors duration-300">
                <item.icon className="w-6 h-6" />
              </div>
              <h3 className="font-heading text-xl font-semibold mb-3">
                {item.title}
              </h3>
              <p className="text-muted-foreground leading-relaxed text-sm">
                {item.text}
              </p>
            </motion.div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default AboutSection;
