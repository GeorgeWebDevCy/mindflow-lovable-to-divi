import { motion, useInView } from "framer-motion";
import { useRef } from "react";
import { MessageSquare, BarChart3, Rocket } from "lucide-react";

const steps = [
  {
    icon: MessageSquare,
    step: "01",
    title: "Discovery & Strategy",
    description:
      "We start with a deep dive into your business, understanding your goals, audience, and competition. A comprehensive strategy is developed that is tailored to your unique needs.",
  },
  {
    icon: BarChart3,
    step: "02",
    title: "Execute & Optimize",
    description:
      "We implement the strategy across the right channels — social media, SEO, ads, email. Every campaign is continuously monitored, tested, and refined for peak performance.",
  },
  {
    icon: Rocket,
    step: "03",
    title: "Grow & Scale",
    description:
      "With data-driven insights and transparent reporting, we identify opportunities to expand your reach, increase conversions, and scale your success to the next level.",
  },
];

const ProcessSection = () => {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: "-80px" });

  return (
    <section id="process" className="py-24 lg:py-32 bg-background">
      <div ref={ref} className="container mx-auto px-6 lg:px-8">
        {/* Header */}
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={isInView ? { opacity: 1, y: 0 } : {}}
          transition={{ duration: 0.6 }}
          className="max-w-3xl mx-auto text-center mb-16"
        >
          <span className="text-sm font-semibold tracking-widest uppercase text-accent mb-4 block">
            How We Work
          </span>
          <h2 className="font-heading text-3xl sm:text-4xl lg:text-5xl font-bold leading-tight mb-6">
            Our <span className="text-gradient-accent">Process</span>
          </h2>
          <p className="text-lg text-muted-foreground leading-relaxed">
            A simple, proven three-step approach to driving real results for
            your business.
          </p>
        </motion.div>

        {/* Timeline */}
        <div className="relative max-w-4xl mx-auto">
          {/* Connecting line */}
          <div className="hidden lg:block absolute left-1/2 top-0 bottom-0 w-px bg-border -translate-x-1/2" />

          <div className="space-y-12 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-8">
            {steps.map((step, i) => (
              <motion.div
                key={step.step}
                initial={{ opacity: 0, y: 30 }}
                animate={isInView ? { opacity: 1, y: 0 } : {}}
                transition={{ duration: 0.6, delay: 0.2 + i * 0.2 }}
                className="relative text-center"
              >
                {/* Step number */}
                <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary text-primary-foreground font-heading text-lg font-bold mb-6 shadow-elevated">
                  {step.step}
                </div>

                <div className="inline-flex items-center justify-center w-10 h-10 rounded-full bg-accent/10 text-accent mb-4">
                  <step.icon className="w-5 h-5" />
                </div>

                <h3 className="font-heading text-xl font-semibold mb-3">
                  {step.title}
                </h3>
                <p className="text-sm text-muted-foreground leading-relaxed max-w-xs mx-auto">
                  {step.description}
                </p>
              </motion.div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
};

export default ProcessSection;
